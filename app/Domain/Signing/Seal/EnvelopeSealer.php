<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\SealRequest;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningPdfs;
use App\Jobs\SealSigningEnvelope;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Seals the finished file of a completed envelope (ADR-046 amendment, gap 5,
 * package S10).
 *
 * The finished file is the signed version (the PDF that was sent, or the
 * version after the last signer who filled in fields) with the summary page
 * appended, and then a PAdES signature by the seal certificate: two
 * incremental updates, so the signed version's bytes are the first bytes of
 * the sealed file (principle 2). The sealed file is a new SEALED row in
 * signing_document_versions, which signing:verify checks like every other
 * version, and becomes the file every completion mail and download carries
 * (SigningFields::finalVersion, CompletionFiles).
 *
 * Level: B-T when a timestamp server is configured, B-B otherwise. When no
 * server answers, the queue tries again for an hour by default (at most 24
 * hours, set by the installation) and the envelope shows "Förseglas"; after that it is sealed at B-B
 * and the sender is told. Never a higher level than reached is stored.
 *
 * The completion mails of an envelope that is being sealed wait for the
 * seal: whichever way it ends (sealed or failed), they go once, from here, in
 * the same transaction that moves the status (SigningFollowUp::afterSeal).
 *
 * The PKCS#12 and its password are read only here, in the job, and handed to
 * the engine on standard input (SealRequest); nothing logs or audits them.
 */
final class EnvelopeSealer
{
    /** The signature field of the seal in the PDF. */
    public const string FIELD_NAME = 'NonoxSeal';

    public const string SEALED = 'SEALED';

    public const string RETRY = 'RETRY';

    public const string FAILED = 'FAILED';

    public const string NOTHING = 'NOTHING';

    /** Codes of seal_failure besides the engine's own (PdfEngineFailure values). */
    public const string NO_CERTIFICATE = 'NO_CERTIFICATE';

    public const string SWITCHED_OFF = 'SWITCHED_OFF';

    public const string FILE_CHANGED = 'FILE_CHANGED';

    public const string JOB_FAILED = 'JOB_FAILED';

    public const string CERTIFICATE_UNREADABLE = 'CERTIFICATE_UNREADABLE';

    /** Why a seal failed, in plain Swedish, for the envelope page. */
    public static function failureLabel(string $code): string
    {
        return match ($code) {
            self::NO_CERTIFICATE => __('det fanns inget giltigt förseglingscertifikat'),
            self::SWITCHED_OFF => __('försegling stängdes av i installationen'),
            self::FILE_CHANGED => __('det signerade dokumentet i lagringen stämde inte med sin kontrollsumma'),
            self::JOB_FAILED => __('förseglingen gick inte att slutföra'),
            self::CERTIFICATE_UNREADABLE => __('företagets förseglingscertifikat gick inte att läsa'),
            default => rtrim(PdfEngineFailure::tryFrom($code)?->message() ?? __('förseglingen gick inte att slutföra'), '.'),
        };
    }

    public function __construct(
        private readonly PdfEngine $engine,
        private readonly SigningFollowUp $followUp,
    ) {}

    /**
     * At completion, in the signing transaction: the attributes that mark the envelope for sealing when sealing
     * applies (switched on, the engine installed, a certificate available). Decides from rows and files only and
     * decrypts nothing, so it cannot break a signature; the job is queued by queue() after the transaction.
     * Must run inside the company.
     *
     * @return array<string, mixed>
     */
    public static function start(SigningEnvelope $envelope): array
    {
        return Sealing::applies($envelope->company_id) ? ['seal_status' => SealStatus::Pending] : [];
    }

    /**
     * After the signing transaction (SignAtLink, SignInSession): queues the seal of the signer's envelope when it
     * is PENDING. Never throws: the signature is saved, and with a sync queue a failing seal must not turn the
     * signer's success into an error page or skip their copy and the follow-up. A seal that could not be queued
     * is queued again by signing:seal-check.
     */
    public static function queue(int $companyId, ?string $envelopeId): void
    {
        if ($envelopeId === null) {
            return;
        }

        try {
            $pending = CurrentCompany::run($companyId, fn (): bool => SigningEnvelope::query()->whereKey($envelopeId)->where('seal_status', SealStatus::Pending->value)->exists());

            if ($pending) {
                SealSigningEnvelope::dispatch($companyId, $envelopeId);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * One attempt to seal. Must run inside the company.
     *
     * @param  bool  $mayWait  false on the last attempt: a timestamp that does not come is then given up
     * @return string SEALED, RETRY (try again later), FAILED or NOTHING (not pending)
     */
    public function attempt(string $envelopeId, bool $mayWait = true): string
    {
        $envelope = SigningEnvelope::query()->find($envelopeId);

        if (! $envelope instanceof SigningEnvelope || $envelope->seal_status !== SealStatus::Pending) {
            return self::NOTHING;
        }

        if (! Sealing::switchedOn()) {
            return $this->fail($envelope->id, self::SWITCHED_OFF);
        }

        if (! $this->engine->isInstalled()) {
            return $this->fail($envelope->id, PdfEngineFailure::NotInstalled->value);
        }

        try {
            $credentials = SealCertificates::forCompany($envelope->company_id);
        } catch (DecryptException) {
            // The stored key cannot be decrypted (a changed APP_KEY): never sealed with another certificate instead.
            return $this->fail($envelope->id, self::CERTIFICATE_UNREADABLE);
        }

        if (! $credentials instanceof SealCredentials) {
            return $this->fail($envelope->id, self::NO_CERTIFICATE);
        }

        $deadline = ($envelope->completed_at ?? CarbonImmutable::now())->addHours(Sealing::retryHours());
        $waiting = $mayWait && CarbonImmutable::now()->lessThan($deadline);
        $urls = Sealing::timestampUrls();
        $withoutTimestamp = false;

        try {
            $sealed = $this->seal($envelope, $credentials, $urls === [] ? SealLevel::B_B : SealLevel::B_T, $urls);
        } catch (PdfEngineException $exception) {
            if ($exception->failure !== PdfEngineFailure::TimestampUnavailable) {
                return $exception->failure->isTransient() && $waiting ? self::RETRY : $this->fail($envelope->id, $exception->failure->value);
            }

            if ($waiting) {
                return self::RETRY;
            }

            // No timestamp server answered in time: sealed at the level reached, B-B, and the sender is told.
            try {
                $sealed = $this->seal($envelope, $credentials, SealLevel::B_B, []);
                $withoutTimestamp = true;
            } catch (PdfEngineException $again) {
                return $this->fail($envelope->id, $again->failure->value);
            } catch (SealFileChanged) {
                return $this->fail($envelope->id, self::FILE_CHANGED);
            }
        } catch (SealFileChanged) {
            return $this->fail($envelope->id, self::FILE_CHANGED);
        }

        return $this->finish($envelope->id, $sealed, $credentials, $withoutTimestamp);
    }

    /**
     * The seal is given up (a permanent engine failure, no certificate, or the job's last attempt): the envelope
     * is FAILED with a code, and its completion mails go with the unsealed file. Must run inside the company.
     */
    public function fail(string $envelopeId, string $code): string
    {
        return DB::transaction(function () use ($envelopeId, $code): string {
            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();

            if (! $envelope instanceof SigningEnvelope || $envelope->seal_status !== SealStatus::Pending) {
                return self::NOTHING;
            }

            $envelope->forceFill(['seal_status' => SealStatus::Failed, 'seal_failure' => mb_substr($code, 0, 30)])->save();
            Audit::record('signing.seal_failed', $envelope, ['envelope_id' => $envelope->id, 'failure' => $code], $envelope->company_id);

            $this->followUp->afterSeal($envelope, SigningFollowUp::SEAL_FAILED);

            return self::FAILED;
        });
    }

    /**
     * The signed version, the summary page appended, then the seal; stored on the signing disk. Outside any
     * transaction (the engine may take a minute).
     *
     * @param  list<string>  $urls
     * @return array{path: string, bytes: int, sha256: string, level: SealLevel, timestampUrl: ?string}
     *
     * @throws PdfEngineException|SealFileChanged
     */
    private function seal(SigningEnvelope $envelope, SealCredentials $credentials, SealLevel $level, array $urls): array
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $input = SigningFields::signedVersion($envelope);
        $expected = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('path', $input)->orderByDesc('id')->value('sha256') ?? $envelope->document_sha256;
        $inputBytes = $disk->get($input);

        // The signed version must still be the stored file: read once and checked against its SHA-256.
        if (! is_string($inputBytes) || ! hash_equals((string) $expected, hash('sha256', $inputBytes))) {
            throw new SealFileChanged;
        }

        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-seal-'.Str::uuid()->toString();
        $files = ['input' => "{$work}-input.pdf", 'appendix' => "{$work}-appendix.pdf", 'appended' => "{$work}-appended.pdf", 'sealed' => "{$work}-sealed.pdf"];

        try {
            file_put_contents($files['input'], $inputBytes);
            file_put_contents($files['appendix'], SigningPdfs::sealAppendix($envelope, $credentials->info, $credentials->source));

            $this->engine->appendPages($files['input'], $files['appendix'], $files['appended']);
            $result = $this->engine->seal($files['appended'], $files['sealed'], new SealRequest(
                level: $level,
                pkcs12: $credentials->pkcs12,
                password: $credentials->password,
                fieldName: self::FIELD_NAME,
                reason: 'Förseglad efter signering / Sealed after signing',
                timestampUrls: $urls,
                timestampTimeoutSeconds: Sealing::timestampTimeout(),
            ));

            $bytes = (string) file_get_contents($result->path);

            // The engine checks it too: the signed version is the start of the sealed file, never rewritten.
            if (! str_starts_with($bytes, $inputBytes)) {
                throw new PdfEngineException(PdfEngineFailure::NotIncremental);
            }

            if ($result->certificateSha256 !== '' && ! hash_equals($credentials->info->fingerprintSha256, strtolower($result->certificateSha256))) {
                throw new PdfEngineException(PdfEngineFailure::BadCredentials);
            }

            $path = 'crm-signing/'.$envelope->company_id.'/'.Str::uuid()->toString().'-forseglad.pdf';
            $disk->put($path, $bytes);

            return ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'level' => $result->level, 'timestampUrl' => $result->timestampUrl];
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @param  array{path: string, bytes: int, sha256: string, level: SealLevel, timestampUrl: ?string}  $sealed
     */
    private function finish(string $envelopeId, array $sealed, SealCredentials $credentials, bool $withoutTimestamp): string
    {
        return DB::transaction(function () use ($envelopeId, $sealed, $credentials, $withoutTimestamp): string {
            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();

            // Another worker finished it first: this file is not used.
            if (! $envelope instanceof SigningEnvelope || $envelope->seal_status !== SealStatus::Pending) {
                DB::afterCommit(fn () => Storage::disk(SigningRequest::DISK)->delete($sealed['path']));

                return self::NOTHING;
            }

            DB::afterRollBack(fn () => Storage::disk(SigningRequest::DISK)->delete($sealed['path']));

            $version = SigningDocumentVersion::create([
                'envelope_id' => $envelope->id,
                'kind' => SigningDocumentVersion::SEALED,
                'path' => $sealed['path'],
                'sha256' => $sealed['sha256'],
                'bytes' => $sealed['bytes'],
            ]);

            $now = CarbonImmutable::now();
            $envelope->forceFill([
                'seal_status' => SealStatus::Sealed,
                'seal_level' => $sealed['level'],
                'sealed_path' => $sealed['path'],
                'sealed_sha256' => $sealed['sha256'],
                'seal_certificate_fingerprint' => $credentials->info->fingerprintSha256,
                'seal_certificate_id' => $credentials->certificateId,
                'sealed_at' => $now,
                'last_timestamp_at' => $sealed['level']->needsTimestamp() ? $now : null,
            ])->save();

            // Ids, enums and booleans only (principle 8).
            Audit::record('signing.sealed', $envelope, [
                'envelope_id' => $envelope->id,
                'version_id' => $version->id,
                'level' => $sealed['level']->value,
                'certificate' => $credentials->source,
                'seal_certificate_id' => $credentials->certificateId,
                'without_timestamp' => $withoutTimestamp,
            ], $envelope->company_id);

            $this->followUp->afterSeal($envelope, $withoutTimestamp ? SigningFollowUp::SEALED_WITHOUT_TIMESTAMP : null);

            // Long-term validation (S11) starts from a seal with a signature timestamp; queued after the commit, never throws.
            if ($sealed['level'] === SealLevel::B_T) {
                DB::afterCommit(fn () => LongTermValidation::queue($envelope->company_id, $envelope->id));
            }

            return self::SEALED;
        });
    }
}
