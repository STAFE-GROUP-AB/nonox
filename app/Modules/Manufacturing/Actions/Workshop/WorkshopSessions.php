<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Models\Company;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Notifications\WorkshopCodeBlocked;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing a worker in on a paired tablet (ADR-071 §7): the person's code
 * is checked on the server, and a short-lived token proves it for the next
 * requests. The token is signed with the application key and holds no
 * personal data beyond ids: person, device, company, a fingerprint of the
 * code that was typed (so a new code ends old sessions) and an expiry. It
 * is refreshed on every use, so the worker is signed out after a few idle
 * minutes, and the tablet signs out on its own much sooner.
 *
 * Guessing is limited at three levels. Five wrong codes in a row lock the
 * person for five minutes. Three such lock-outs within 24 hours block the
 * person until a manager sets a new code, and the managers are told. And a
 * tablet that has had twenty wrong codes in an hour, on anyone, is paused
 * for the rest of that hour. Locking is per person, so guessing at one tablet
 * does not help at another, and the pause is per tablet, so a person who is
 * guessed at cannot be locked out from every tablet by one.
 */
final class WorkshopSessions
{
    public const int MINUTES = 10;

    /** Wrong codes on one tablet per hour before it is paused. */
    public const int DEVICE_FAILURES_PER_HOUR = 20;

    /**
     * @return array{token: string, name: string}|array{locked: true, blocked: bool}|array{paused: true}|null the session, a lock-out, a paused tablet, or null for a wrong code
     */
    public function signIn(WorkshopDevice $device, int $personId, string $code): ?array
    {
        $failures = self::failuresKey($device);

        if (self::isPaused($device)) {
            return ['paused' => true];
        }

        $result = DB::transaction(function () use ($device, $personId, $code): ?array {
            $person = WorkshopPerson::query()->active()->whereKey($personId)->lockForUpdate()->first();

            if (! $person instanceof WorkshopPerson || ! $person->hasCode()) {
                return null;
            }

            if ($person->isLocked()) {
                return ['locked' => true, 'blocked' => $person->isBlocked()];
            }

            if ($person->codeMatches($code)) {
                $person->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

                return ['token' => self::issue($device, $person), 'name' => $person->name];
            }

            $attempts = $person->failed_attempts + 1;

            if ($attempts < WorkshopPerson::MAX_ATTEMPTS) {
                $person->forceFill(['failed_attempts' => $attempts])->save();

                return null;
            }

            return $this->lockOut($person);
        });

        if ($result === null || isset($result['locked'])) {
            RateLimiter::hit($failures, 3600);
        }

        return $result;
    }

    /** Whether the tablet is paused after too many wrong codes this hour. */
    public static function isPaused(WorkshopDevice $device): bool
    {
        return RateLimiter::tooManyAttempts(self::failuresKey($device), self::DEVICE_FAILURES_PER_HOUR);
    }

    private static function failuresKey(WorkshopDevice $device): string
    {
        return 'workshop-device-failures:'.$device->id;
    }

    /**
     * @return array{locked: true, blocked: bool}
     */
    private function lockOut(WorkshopPerson $person): array
    {
        $now = CarbonImmutable::now();
        $recent = $person->last_lockout_at !== null && $person->last_lockout_at->greaterThan($now->subHours(WorkshopPerson::LOCKOUT_WINDOW_HOURS));
        $lockouts = ($recent ? $person->lockouts : 0) + 1;
        $blocked = $lockouts >= WorkshopPerson::ESCALATE_AFTER;

        $person->forceFill([
            'failed_attempts' => 0,
            'locked_until' => $now->addMinutes(WorkshopPerson::LOCK_MINUTES),
            'lockouts' => $lockouts,
            'last_lockout_at' => $now,
            'code_blocked_at' => $blocked ? $now : null,
        ])->save();

        if ($blocked) {
            $this->tellManagers($person);
        }

        return ['locked' => true, 'blocked' => $blocked];
    }

    /** Everyone who may set the terminal up gets a notice that the code is blocked. */
    private function tellManagers(WorkshopPerson $person): void
    {
        $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($person->company_id));

        if (! $company instanceof Company) {
            return;
        }

        $notice = new WorkshopCodeBlocked(
            $company->id,
            (string) $company->name,
            $person->id,
            __('Koden för :name är spärrad efter upprepade felaktiga försök. Välj en ny kod under Tillverkning, Verkstadsterminal.', ['name' => $person->name]),
        );

        foreach ($company->allUsers() as $user) {
            if ($user->hasTeamPermission($company, 'production:report')) {
                $user->notify($notice);
            }
        }
    }

    /** The active person a token belongs to on this device, or null (expired, forged, another device, a new code). */
    public function person(WorkshopDevice $device, string $token): ?WorkshopPerson
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || ($data['d'] ?? null) !== $device->id || ($data['c'] ?? null) !== $device->company_id || ($data['x'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        $person = WorkshopPerson::query()->active()->find($data['p'] ?? 0);

        if (! $person instanceof WorkshopPerson || ! $person->hasCode() || $person->isLocked() || ($data['f'] ?? '') !== self::fingerprint($person)) {
            return null;
        }

        return $person;
    }

    /** A fresh token for the same person, so activity keeps the session alive. */
    public function refresh(WorkshopDevice $device, WorkshopPerson $person): string
    {
        return self::issue($device, $person);
    }

    private static function issue(WorkshopDevice $device, WorkshopPerson $person): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'p' => $person->id,
            'd' => $device->id,
            'c' => $device->company_id,
            'f' => self::fingerprint($person),
            'x' => CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp(),
        ])), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload);
    }

    private static function fingerprint(WorkshopPerson $person): string
    {
        return substr(hash('sha256', (string) $person->code_verifier), 0, 12);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'workshop-session:'.$payload, (string) config('app.key'));
    }
}
