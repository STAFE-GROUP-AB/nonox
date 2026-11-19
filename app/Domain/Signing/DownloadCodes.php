<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Domain\Signing\Verification\CheckRefused;
use App\Domain\Signing\Verification\SignerChecks;
use App\Mail\SigningDownloadCodeMail;
use App\Models\Company;
use App\Models\SigningDownloadCode;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The e-mail code before the files of a confidential envelope are downloaded
 * through a completion link (ADR-046 S13 amendment, owner decision
 * 2026-11-19). It follows the signer's e-mail code (S3), with the link as the
 * subject instead of a signer:
 *
 * - six digits from random_int, sent to the link's own address only, valid ten
 *   minutes, five tries each, used once; only an HMAC-SHA256 with the app key
 *   over the link id and the code is stored, every comparison is hash_equals;
 * - at most three codes an hour per link, a new one only after sixty seconds:
 *   inside that time the code already sent is reused and no second mail goes
 *   out (the send runs behind a cache lock, so a double click mails once);
 * - ten failed tries over all of a link's codes lock the code step for good:
 *   the sender closes the link and sends a new one;
 * - a correct code unlocks the downloads of that link for the browser session
 *   for thirty minutes (UNLOCK_MINUTES). Nothing is stored about the browser.
 *
 * Every step is an audit entry with ids and counts only, never a code, an
 * address, an IP address or a browser. A link of an envelope that is not
 * confidential asks for no code.
 */
final class DownloadCodes
{
    public const int CODE_MINUTES = 10;

    public const int ATTEMPTS_PER_CODE = 5;

    public const int CODES_PER_HOUR = 3;

    public const int RESEND_SECONDS = 60;

    /** Failed tries over all of a link's codes that close the code step. */
    public const int LOCK_AFTER_FAILURES = 10;

    /** How long a correct code opens the downloads of a link in the browser session. */
    public const int UNLOCK_MINUTES = 30;

    public const string SENT = 'SENT';

    public const string REUSED = 'REUSED';

    public static function mac(int $linkId, string $code): string
    {
        return hash_hmac('sha256', "download|{$linkId}|{$code}", (string) config('app.key'));
    }

    /** Whether the files of this link are held behind a code: the envelope is confidential. Runs inside the company. */
    public static function required(SigningDownloadLink $link): bool
    {
        return CurrentCompany::run($link->company_id, fn (): bool => (bool) SigningEnvelope::query()->whereKey($link->envelope_id)->value('confidential'));
    }

    public static function unlocked(Session $session, SigningDownloadLink $link): bool
    {
        $until = $session->get(self::sessionKey($link));

        return is_int($until) && $until > CarbonImmutable::now()->getTimestamp();
    }

    public static function grant(Session $session, SigningDownloadLink $link): void
    {
        $session->put(self::sessionKey($link), CarbonImmutable::now()->addMinutes(self::UNLOCK_MINUTES)->getTimestamp());
    }

    /** Whether the code step is closed for good by too many failed tries. Runs inside the company. */
    public static function isLocked(SigningDownloadLink $link): bool
    {
        return CurrentCompany::run($link->company_id, fn (): bool => (int) SigningDownloadCode::query()->where('download_link_id', $link->id)->sum('attempts') >= self::LOCK_AFTER_FAILURES);
    }

    /** Whether the newest code of the link can still be typed (so the page shows the field, also after a reload). Runs inside the company. */
    public static function hasOpenCode(SigningDownloadLink $link): bool
    {
        return CurrentCompany::run($link->company_id, fn (): bool => SigningDownloadCode::query()->where('download_link_id', $link->id)->orderByDesc('id')->first()?->isOpen() === true);
    }

    /**
     * "Skicka kod": a new code to the link's own address, or the one sent within the last minute is reused.
     *
     * @return self::SENT|self::REUSED
     *
     * @throws CheckRefused
     */
    public function send(SigningDownloadLink $link): string
    {
        if (Company::query()->find($link->company_id)?->isTestCompany() === true) {
            throw new CheckRefused(CheckRefused::TEST_COMPANY);
        }

        try {
            return Cache::lock("signing-download-code:{$link->id}", 15)->block(10, fn (): string => $this->sendLocked($link));
        } catch (LockTimeoutException) {
            throw new CheckRefused(CheckRefused::NOT_SENT);
        }
    }

    /**
     * The recipient types the code. Returns when it was right; the caller grants the session.
     *
     * @throws CheckRefused
     * @throws ValidationException
     */
    public function verify(SigningDownloadLink $link, mixed $input): void
    {
        $code = preg_replace('/\s+/', '', is_string($input) ? trim($input) : '') ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => __('Skriv koden med sex siffror.')]);
        }

        $result = CurrentCompany::run($link->company_id, fn (): array => DB::transaction(function () use ($link, $code): array {
            $codes = SigningDownloadCode::query()->where('download_link_id', $link->id);
            // The newest code is locked first, so two tries at once are counted one after the other and the sum below is read under that lock.
            $row = (clone $codes)->orderByDesc('id')->lockForUpdate()->first();

            if ((int) (clone $codes)->sum('attempts') >= self::LOCK_AFTER_FAILURES) {
                return ['refused' => CheckRefused::LOCKED];
            }

            if (! $row instanceof SigningDownloadCode) {
                return ['refused' => CheckRefused::NO_CODE];
            }

            if (! $row->isOpen()) {
                return ['refused' => CheckRefused::EXPIRED];
            }

            if (hash_equals($row->code_mac, self::mac($link->id, $code))) {
                $row->forceFill(['verified_at' => CarbonImmutable::now()])->save();
                Audit::record('signing.download_code_verified', $link, $this->payload($link), $link->company_id);

                return ['ok' => true];
            }

            $row->forceFill(['attempts' => $row->attempts + 1])->save();
            Audit::record('signing.download_code_failed', $link, $this->payload($link) + ['attempts' => $row->attempts], $link->company_id);

            return ['refused' => CheckRefused::WRONG, 'remaining' => max(0, $row->max_attempts - $row->attempts)];
        }));

        if (isset($result['refused'])) {
            throw new CheckRefused($result['refused'], (int) ($result['remaining'] ?? 0));
        }
    }

    /** @return self::SENT|self::REUSED */
    private function sendLocked(SigningDownloadLink $link): string
    {
        $result = CurrentCompany::run($link->company_id, fn (): array => DB::transaction(function () use ($link): array {
            $codes = SigningDownloadCode::query()->where('download_link_id', $link->id);

            if ((int) (clone $codes)->sum('attempts') >= self::LOCK_AFTER_FAILURES) {
                return ['refused' => CheckRefused::LOCKED];
            }

            $now = CarbonImmutable::now();
            $latest = (clone $codes)->orderByDesc('id')->first();

            if ($latest instanceof SigningDownloadCode && $latest->isOpen($now) && $latest->sent_at->greaterThan($now->subSeconds(self::RESEND_SECONDS))) {
                return ['reused' => true];
            }

            if ((clone $codes)->where('sent_at', '>', $now->subHour())->count() >= self::CODES_PER_HOUR) {
                return ['refused' => CheckRefused::TOO_MANY_CODES];
            }

            $code = SignerChecks::generate();
            $row = SigningDownloadCode::create([
                'company_id' => $link->company_id,
                'download_link_id' => $link->id,
                'code_mac' => self::mac($link->id, $code),
                'sent_at' => $now,
                'expires_at' => $now->addMinutes(self::CODE_MINUTES),
                'max_attempts' => self::ATTEMPTS_PER_CODE,
            ]);

            return ['code' => $code, 'row' => $row];
        }));

        if (isset($result['refused'])) {
            throw new CheckRefused($result['refused']);
        }

        if (isset($result['reused'])) {
            return self::REUSED;
        }

        try {
            $company = $this->sendMail($link, $result['code']);
        } catch (Throwable $exception) {
            Log::error('Signing download code mail failed', ['download_link_id' => $link->id, 'exception' => $exception::class]);
            // The mail did not go out: the code goes too, so the next click sends a new one instead of answering REUSED.
            CurrentCompany::run($link->company_id, fn () => SigningDownloadCode::query()->whereKey($result['row']->id)->delete());
            Audit::record('signing.download_code_not_sent', $link, $this->payload($link), $link->company_id);

            throw new CheckRefused(CheckRefused::NOT_SENT);
        }

        // After a mail that went out nothing below can make the code count as unsent.
        Audit::record('signing.download_code_sent', $link, $this->payload($link) + ['code_id' => $result['row']->id], $link->company_id);
        // Counted against the hour's quota, never refused: three an hour per link already bounds it.
        MailQuota::count($company, 'documents', 1);

        return self::SENT;
    }

    private function sendMail(SigningDownloadLink $link, string $code): Company
    {
        $company = Company::query()->findOrFail($link->company_id);
        $title = (string) CurrentCompany::run($company, fn (): ?string => SigningEnvelope::query()->whereKey($link->envelope_id)->value('title'));

        Mail::to($link->email)->send(new SigningDownloadCodeMail($company, $title, $code, self::CODE_MINUTES, $link->language));

        return $company;
    }

    /** @return array{download_link_id: int, envelope_id: string} */
    private function payload(SigningDownloadLink $link): array
    {
        return ['download_link_id' => $link->id, 'envelope_id' => $link->envelope_id];
    }

    private static function sessionKey(SigningDownloadLink $link): string
    {
        return "signing.download-unlocked.{$link->id}";
    }
}
