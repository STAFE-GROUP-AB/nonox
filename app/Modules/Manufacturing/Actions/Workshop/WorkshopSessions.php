<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Signing a worker in on a paired tablet (ADR-071 §7): the person's code
 * is checked on the server, and a short-lived token proves it for the next
 * requests. The token is signed with the application key and holds no
 * personal data beyond ids: person, device, company, a fingerprint of the
 * code that was typed (so a new code ends old sessions) and an expiry. It
 * is refreshed on every use, so the worker is signed out after a few idle
 * minutes, and the tablet signs out on its own much sooner.
 *
 * Five wrong codes in a row lock the person for five minutes; a manager
 * can set a new code to lift it. Locking is per person, not per tablet, so
 * guessing at one tablet does not help at another.
 */
final class WorkshopSessions
{
    public const int MINUTES = 10;

    /**
     * @return array{token: string, name: string}|array{locked: true}|null the session, a lock-out, or null for a wrong code
     */
    public function signIn(WorkshopDevice $device, int $personId, string $code): ?array
    {
        return DB::transaction(function () use ($device, $personId, $code): ?array {
            $person = WorkshopPerson::query()->active()->whereKey($personId)->lockForUpdate()->first();

            if (! $person instanceof WorkshopPerson || ! $person->hasCode()) {
                return null;
            }

            if ($person->isLocked()) {
                return ['locked' => true];
            }

            if ($person->codeMatches($code)) {
                $person->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

                return ['token' => self::issue($device, $person), 'name' => $person->name];
            }

            $attempts = $person->failed_attempts + 1;
            $locked = $attempts >= WorkshopPerson::MAX_ATTEMPTS;
            $person->forceFill([
                'failed_attempts' => $locked ? 0 : $attempts,
                'locked_until' => $locked ? CarbonImmutable::now()->addMinutes(WorkshopPerson::LOCK_MINUTES) : null,
            ])->save();

            return $locked ? ['locked' => true] : null;
        });
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
