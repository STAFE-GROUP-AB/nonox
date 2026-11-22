<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verification;

use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\Providers\ProviderRegistry;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningUnavailable;
use App\Mail\SigningCodeMail;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningProvider;
use App\Models\SigningRequest;
use App\Models\SigningVerificationCode;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checking the signer before the document is shown (ADR-046 amendment
 * 2026-11-18, gap 3, package S3). One service for every code a signer
 * types: the e-mail code and the access code at /signera, and the ÄTA code
 * typed on the field worker's phone (ADR-074 O4).
 *
 * - Codes are six digits from random_int. Only an HMAC-SHA256 with the app
 *   key over the request id and the code is stored (mac); every comparison
 *   is hash_equals. An access code is stored with Hash::make and compared
 *   with Hash::check (bcrypt, constant time), never logged or shown again.
 * - An e-mail code is valid ten minutes, takes five attempts and is used
 *   once; only the newest code of a signer counts. At most three codes an
 *   hour per signer, and a new one only after sixty seconds: inside that
 *   time the code already sent is reused and no second mail goes out (the
 *   send also runs behind a cache lock, so a double click mails once).
 * - Failed checks are counted on the signer; ten for e-mail codes, five for
 *   an access code, lock the signer out. The sender is told and can unlock.
 * - Every step is an event in the envelope's hash chain (S2): CODE_SENT,
 *   VERIFIED, VERIFY_FAILED, LOCKED, UNLOCKED, with ids, the method and
 *   counts in the payload, never a code, an IP address or a browser.
 * - Lock order as every signing path: the document, then the signer row,
 *   then the code row, then the envelope (the chain's lock). A failed try is
 *   counted in its own committed transaction; the error is thrown after.
 *
 * Neither check changes the level of the signature: it stays a simple
 * electronic signature (eIDAS art. 3(10)).
 * TODO(legal-verify): ADR-046 gap 3 — that an e-mail code or an access code does not make the signature advanced under eIDAS art. 26; the UI and the manual only say that it reduces the risk of a forwarded link.
 */
final class SignerChecks
{
    public const int CODE_MINUTES = 10;

    public const int ATTEMPTS_PER_CODE = 5;

    public const int CODES_PER_HOUR = 3;

    public const int RESEND_SECONDS = 60;

    public const int LOCK_AFTER_EMAIL_FAILURES = 10;

    public const int LOCK_AFTER_ACCESS_FAILURES = 5;

    public const int ACCESS_CODE_MIN = 6;

    public const int ACCESS_CODE_MAX = 64;

    /** bcrypt reads at most 72 bytes; a longer code would be compared on its first 72 bytes only. */
    public const int ACCESS_CODE_MAX_BYTES = 72;

    /** A code of digits only must be at least this long: a short number is guessed or is a date, phone or personal number. */
    public const int ACCESS_CODE_MIN_DIGITS = 8;

    /** Codes that are guessed first; compared case-insensitively, without spaces. */
    private const array COMMON_ACCESS_CODES = [
        '12345678', '123456789', '1234567890', '87654321', '11111111', '00000000', '12341234', '11223344',
        'password', 'password1', 'passw0rd', 'losenord', 'lösenord', 'qwerty', 'qwertyui', 'qwerty123', 'abc123',
        'abcdef', 'abcdefgh', 'abc12345', 'asdfgh', 'asdfghjk', 'iloveyou', 'letmein', 'welcome', 'välkommen',
        'sommar', 'sommar2026', 'hemlig', 'hemligt', 'signera', 'signering', 'kod123', 'kod1234', 'admin123',
    ];

    /** The characters of a suggested code: no 0/O, 1/l/I, so it can be read out over the phone. */
    private const string SUGGEST_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const string SENT = 'SENT';

    public const string REUSED = 'REUSED';

    public function __construct(
        private readonly SigningChain $chain,
        private readonly SigningFollowUp $followUp,
    ) {}

    /** Six digits from random_int. */
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    /** An HMAC keyed with the application key: a copy of the table alone cannot be tried against all codes. */
    public static function mac(int $signingRequestId, string $code): string
    {
        return hash_hmac('sha256', "{$signingRequestId}|{$code}", (string) config('app.key'));
    }

    public static function matches(string $mac, int $signingRequestId, string $code): bool
    {
        return hash_equals($mac, self::mac($signingRequestId, $code));
    }

    /**
     * The sender's access code, validated and hashed for the signer row.
     *
     * @throws ValidationException
     */
    public static function hashAccessCode(mixed $code, string $field): string
    {
        return Hash::make(self::validAccessCode($code, $field));
    }

    /**
     * The rules for an access code without hashing it (bcrypt is slow on purpose): the trimmed code, or a refusal.
     * The bulk send's preview checks every row with this and hashes only when an envelope is made (ADR-046 S15).
     *
     * @throws ValidationException
     */
    public static function validAccessCode(mixed $code, string $field): string
    {
        $code = is_string($code) ? trim($code) : '';

        if (mb_strlen($code) < self::ACCESS_CODE_MIN || mb_strlen($code) > self::ACCESS_CODE_MAX || strlen($code) > self::ACCESS_CODE_MAX_BYTES || preg_match('/^[^\p{C}]+$/u', $code) !== 1) {
            throw ValidationException::withMessages([$field => __('Åtkomstkoden ska ha :min till :max tecken.', ['min' => self::ACCESS_CODE_MIN, 'max' => self::ACCESS_CODE_MAX])]);
        }

        if (preg_match('/^\d+$/', $code) === 1 && strlen($code) < self::ACCESS_CODE_MIN_DIGITS) {
            throw ValidationException::withMessages([$field => __('En åtkomstkod med bara siffror ska ha minst :count siffror. Blanda gärna bokstäver och siffror.', ['count' => self::ACCESS_CODE_MIN_DIGITS])]);
        }

        if (in_array(mb_strtolower(preg_replace('/\s+/u', '', $code) ?? ''), self::COMMON_ACCESS_CODES, true)) {
            throw ValidationException::withMessages([$field => __('Koden är för lätt att gissa. Välj en annan, eller tryck på Föreslå kod.')]);
        }

        return $code;
    }

    /** "Föreslå kod": ten random characters that are easy to read out (about 57 bits). */
    /**
     * The company's external providers a sender may choose as a signer's check (ADR-046 S17): "PROVIDER:<id>",
     * "Underskrift hos <name>". None while external signing cannot be used. Runs inside the current company.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function providerOptions(): array
    {
        return array_map(fn (SigningProvider $provider): array => [
            'value' => SignerCheck::Provider->value.':'.$provider->id,
            'label' => __('Underskrift hos :provider', ['provider' => $provider->name]),
        ], ProviderRegistry::choosable());
    }

    public static function suggestAccessCode(): string
    {
        $alphabet = self::SUGGEST_ALPHABET;
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * "Skicka kod": a new e-mail code to the signer's own address, or the one
     * sent within the last minute is reused (no second mail).
     *
     * @return self::SENT|self::REUSED
     *
     * @throws CheckRefused
     * @throws SigningUnavailable
     */
    public function sendCode(SigningRequest $request): string
    {
        if ($request->verification !== SignerCheck::EmailCode) {
            throw new CheckRefused(CheckRefused::NO_CODE);
        }

        // A test company's mail never leaves NONOX (ADR-032), so no code can reach the signer: say so instead of sending nothing.
        if (Company::query()->find($request->company_id)?->isTestCompany() === true) {
            throw new CheckRefused(CheckRefused::TEST_COMPANY);
        }

        try {
            return $this->sendCodeLocked($request);
        } catch (LockTimeoutException) {
            throw new CheckRefused(CheckRefused::NOT_SENT);
        }
    }

    /**
     * @return self::SENT|self::REUSED
     *
     * @throws CheckRefused
     * @throws SigningUnavailable
     * @throws LockTimeoutException
     */
    private function sendCodeLocked(SigningRequest $request): string
    {
        return Cache::lock("signing-code:{$request->id}", 15)->block(10, function () use ($request): string {
            $result = CurrentCompany::run($request->company_id, fn (): array => DB::transaction(function () use ($request): array {
                $locked = $this->lockPending($request);

                if ($locked->locked_at !== null) {
                    return ['refused' => CheckRefused::LOCKED];
                }

                $now = CarbonImmutable::now();
                $codes = SigningVerificationCode::query()->where('signing_request_id', $locked->id)->where('channel', SigningVerificationCode::LINK);
                $latest = (clone $codes)->orderByDesc('id')->first();

                if ($latest instanceof SigningVerificationCode && $latest->isOpen($now) && $latest->sent_at->greaterThan($now->subSeconds(self::RESEND_SECONDS))) {
                    return ['reused' => true];
                }

                if ((clone $codes)->where('sent_at', '>', $now->subHour())->count() >= self::CODES_PER_HOUR) {
                    return ['refused' => CheckRefused::TOO_MANY_CODES];
                }

                $code = self::generate();
                $row = SigningVerificationCode::create([
                    'signing_request_id' => $locked->id,
                    'channel' => SigningVerificationCode::LINK,
                    'code_mac' => self::mac($locked->id, $code),
                    'sent_at' => $now,
                    'expires_at' => $now->addMinutes(self::CODE_MINUTES),
                    'max_attempts' => self::ATTEMPTS_PER_CODE,
                ]);

                $this->chain->record($locked, SigningEventType::CodeSent, payload: ['channel' => SigningVerificationCode::LINK, 'code_id' => $row->id]);

                return ['code' => $code, 'request' => $locked, 'row' => $row];
            }, SignAtLink::ATTEMPTS));

            if (isset($result['refused'])) {
                throw new CheckRefused($result['refused']);
            }

            if (isset($result['reused'])) {
                return self::REUSED;
            }

            try {
                $this->mail($result['request'], $result['code']);
            } catch (CheckRefused $refused) {
                // The mail did not go out: the code is spent, so the next click sends a new one instead of answering REUSED.
                $this->spend($result['row']);

                throw $refused;
            }

            return self::SENT;
        });
    }

    /**
     * The signer types the e-mail code or the access code. Returns when the
     * check passed; the caller grants the session pass (SignerPass).
     *
     * @throws CheckRefused
     * @throws SigningUnavailable
     * @throws ValidationException
     */
    public function verify(SigningRequest $request, mixed $input): void
    {
        $check = $request->verification;
        $code = is_string($input) ? trim($input) : '';

        if ($check === SignerCheck::EmailCode) {
            $code = preg_replace('/\s+/', '', $code) ?? '';

            if (preg_match('/^\d{6}$/', $code) !== 1) {
                throw ValidationException::withMessages(['code' => __('Skriv koden med sex siffror.')]);
            }
        } elseif ($check === SignerCheck::AccessCode) {
            if ($code === '' || mb_strlen($code) > self::ACCESS_CODE_MAX || strlen($code) > self::ACCESS_CODE_MAX_BYTES) {
                throw ValidationException::withMessages(['code' => __('Skriv åtkomstkoden du har fått av avsändaren.')]);
            }
        } else {
            throw new CheckRefused(CheckRefused::NO_CODE);
        }

        $result = CurrentCompany::run($request->company_id, fn (): array => DB::transaction(function () use ($request, $check, $code): array {
            $locked = $this->lockPending($request);

            if ($locked->locked_at !== null) {
                return ['refused' => CheckRefused::LOCKED];
            }

            if ($check === SignerCheck::AccessCode) {
                if (Hash::check($code, (string) $locked->access_code_hash)) {
                    return $this->passed($locked, $check, null);
                }

                return $this->failed($locked, $check, null);
            }

            $row = SigningVerificationCode::query()->where('signing_request_id', $locked->id)->where('channel', SigningVerificationCode::LINK)->orderByDesc('id')->lockForUpdate()->first();

            if (! $row instanceof SigningVerificationCode) {
                return ['refused' => CheckRefused::NO_CODE];
            }

            if (! $row->isOpen()) {
                return ['refused' => CheckRefused::EXPIRED];
            }

            if (self::matches($row->code_mac, $locked->id, $code)) {
                $row->forceFill(['verified_at' => CarbonImmutable::now()])->save();

                return $this->passed($locked, $check, $row);
            }

            $row->forceFill(['attempts' => $row->attempts + 1])->save();

            return $this->failed($locked, $check, $row);
        }, SignAtLink::ATTEMPTS));

        if (isset($result['refused'])) {
            throw new CheckRefused($result['refused'], (int) ($result['remaining'] ?? 0));
        }
    }

    /**
     * "Lås upp": the sender lets a locked-out signer try again. The failed
     * tries stay in the chain; the counter starts over.
     *
     * @throws ValidationException
     */
    public function unlock(User $user, Company $company, SigningRequest $request): void
    {
        $document = CurrentCompany::run($company, fn (): ?Signable => $request->document());
        abort_unless($document instanceof Signable && $request->company_id === $company->id, 404);
        SendForSigning::authorizeAct($document, $user, CurrentCompany::run($company, fn (): ?\App\Models\SigningEnvelope => $request->envelope_id === null ? null : SigningEnvelope::query()->find($request->envelope_id)));

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $request): void {
            $request->document(lock: true);
            $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->locked_at === null || ! $locked->isUsable()) {
                throw ValidationException::withMessages(['signing' => __('Länken är inte låst.')]);
            }

            $failures = $locked->verification_failures;
            $locked->forceFill(['locked_at' => null, 'verification_failures' => 0])->save();
            $this->chain->record($locked, SigningEventType::Unlocked, $user->id, payload: ['method' => $locked->verification->value, 'failures' => $failures]);
            Audit::record('signing.unlocked', $locked, ['signing_request_id' => $locked->id, 'method' => $locked->verification->value, 'failures' => $failures], $company, $user);
        }, SignAtLink::ATTEMPTS));
    }

    /**
     * An ÄTA code for the field worker's phone (ADR-074 O4): a code row of
     * the ON_SITE channel and its CODE_SENT event. Runs in the caller's
     * transaction, inside the company, with the document locked.
     *
     * @return array{code: string, row: SigningVerificationCode}
     */
    public function issueOnSite(SigningRequest $request, int $minutes, int $attempts): array
    {
        $code = self::generate();
        $now = CarbonImmutable::now();
        $row = SigningVerificationCode::create([
            'signing_request_id' => $request->id,
            'channel' => SigningVerificationCode::ON_SITE,
            'code_mac' => self::mac($request->id, $code),
            'sent_at' => $now,
            'expires_at' => $now->addMinutes($minutes),
            'max_attempts' => $attempts,
        ]);

        $this->chain->record($request, SigningEventType::CodeSent, payload: ['channel' => SigningVerificationCode::ON_SITE, 'code_id' => $row->id]);

        return ['code' => $code, 'row' => $row];
    }

    /**
     * Checks an ÄTA code typed on site. The caller holds the document and
     * the code row locked, in its transaction. The signer row is not locked
     * out: as before, the worker sends a new code instead.
     *
     * @return 'ok'|'wrong'|'expired'
     */
    public function checkOnSite(SigningVerificationCode $row, SigningRequest $request, string $code): string
    {
        // A link replaced by a newer one (its envelope closed) takes no more events; its code is spent.
        if (! $row->isOpen() || $request->status !== SigningStatus::Pending || ! $request->isUsable()) {
            return 'expired';
        }

        if (self::matches($row->code_mac, $request->id, $code)) {
            $row->forceFill(['verified_at' => CarbonImmutable::now()])->save();
            $this->chain->record($request, SigningEventType::Verified, payload: ['channel' => SigningVerificationCode::ON_SITE, 'code_id' => $row->id, 'method' => SigningVerificationCode::ON_SITE]);

            return 'ok';
        }

        $row->forceFill(['attempts' => $row->attempts + 1])->save();
        $this->chain->record($request, SigningEventType::VerifyFailed, payload: ['channel' => SigningVerificationCode::ON_SITE, 'code_id' => $row->id, 'attempts' => $row->attempts]);

        return 'wrong';
    }

    /** How many failed checks lock a signer out. */
    public static function lockAfter(SignerCheck $check): int
    {
        return $check === SignerCheck::AccessCode ? self::LOCK_AFTER_ACCESS_FAILURES : self::LOCK_AFTER_EMAIL_FAILURES;
    }

    /**
     * The document, then the signer row, locked; the row must still be
     * signable.
     *
     * @throws SigningUnavailable
     */
    private function lockPending(SigningRequest $request): SigningRequest
    {
        $request->document(lock: true);
        $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== SigningStatus::Pending || ! $locked->isUsable()) {
            throw new SigningUnavailable(SigningUnavailable::GONE);
        }

        return $locked;
    }

    /** @return array{passed: true} */
    private function passed(SigningRequest $locked, SignerCheck $check, ?SigningVerificationCode $row): array
    {
        $locked->forceFill(['verified_at' => CarbonImmutable::now()])->save();
        $this->chain->record($locked, SigningEventType::Verified, payload: array_filter(['channel' => SigningVerificationCode::LINK, 'method' => $check->value, 'code_id' => $row?->id], fn (mixed $value): bool => $value !== null));

        return ['passed' => true];
    }

    /** @return array{refused: string, remaining?: int} */
    private function failed(SigningRequest $locked, SignerCheck $check, ?SigningVerificationCode $row): array
    {
        $failures = $locked->verification_failures + 1;
        $limit = self::lockAfter($check);
        $locked->forceFill(['verification_failures' => $failures])->save();
        $this->chain->record($locked, SigningEventType::VerifyFailed, payload: array_filter(['channel' => SigningVerificationCode::LINK, 'method' => $check->value, 'code_id' => $row?->id, 'failures' => $failures], fn (mixed $value): bool => $value !== null));

        if ($failures >= $limit) {
            $locked->forceFill(['locked_at' => CarbonImmutable::now()])->save();
            $this->chain->record($locked, SigningEventType::Locked, payload: ['method' => $check->value, 'failures' => $failures]);
            Audit::record('signing.locked', $locked, ['signing_request_id' => $locked->id, 'method' => $check->value, 'failures' => $failures], $locked->company_id);
            $this->followUp->locked($locked);

            return ['refused' => CheckRefused::LOCKED];
        }

        $remaining = $limit - $failures;

        if ($row instanceof SigningVerificationCode) {
            $remaining = min($remaining, $row->max_attempts - $row->attempts);

            if ($remaining === 0) {
                return ['refused' => CheckRefused::EXPIRED];
            }
        }

        return ['refused' => CheckRefused::WRONG, 'remaining' => $remaining];
    }

    /** A code whose mail failed: its attempts are used up (the trigger lets attempts only grow), so it can never be typed. */
    private function spend(SigningVerificationCode $row): void
    {
        CurrentCompany::run($row->company_id, fn () => DB::transaction(function () use ($row): void {
            $locked = SigningVerificationCode::query()->whereKey($row->id)->lockForUpdate()->first();

            if ($locked instanceof SigningVerificationCode && $locked->verified_at === null && $locked->attempts < $locked->max_attempts) {
                $locked->forceFill(['attempts' => $locked->max_attempts])->save();
            }
        }));
    }

    /** The code goes out at once, after the commit. */
    private function mail(SigningRequest $request, string $code): void
    {
        $company = Company::query()->findOrFail($request->company_id);

        try {
            $title = CurrentCompany::run($company, fn (): string => SigningPdfs::title($request));
            Mail::to($request->email)->send(new SigningCodeMail($company, $title, $code, self::CODE_MINUTES));
        } catch (Throwable $exception) {
            Log::error('Signing code mail failed', ['signing_request_id' => $request->id, 'exception' => $exception::class]);

            throw new CheckRefused(CheckRefused::NOT_SENT);
        }

        // Counted against the hour's quota, never refused: the three-an-hour limit per signer already bounds it.
        MailQuota::count($company, 'documents', 1);
    }
}
