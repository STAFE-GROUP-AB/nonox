<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use App\Domain\Signing\Pdf\LongTermResult;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\TimestampFact;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds long-term validation to a sealed file (ADR-046 amendment, gap 5,
 * package S11; the rules are in {@see LongTermValidation}).
 *
 * One attempt does one step: the validation data of a B-T seal (B-LT), or a
 * renewal by a document timestamp (B-LTA). The input is the envelope's newest
 * file (the newest TIMESTAMPED version, else the SEALED one), read once and
 * checked against its stored SHA-256; the output must start with exactly its
 * bytes (the engine checks it, and so does this class) and is stored as a new
 * TIMESTAMPED version. The envelope's columns move under a row lock in one
 * transaction with the version row and the audit entry, after checking again
 * that the envelope still exists, is still within retention and that no other
 * worker added a version meanwhile.
 *
 * Nothing here decrypts a key: long-term validation needs no seal key.
 */
final class EnvelopeTimestamper
{
    public const string EXTENDED = 'EXTENDED';

    public const string FAILED = 'FAILED';

    public const string NOTHING = 'NOTHING';

    public const string STEP_VALIDATION_DATA = 'VALIDATION_DATA';

    public const string STEP_DOCUMENT_TIMESTAMP = 'DOCUMENT_TIMESTAMP';

    /** Codes of ltv_failure besides the engine's own (PdfEngineFailure values). */
    public const string FILE_CHANGED = 'FILE_CHANGED';

    public const string NO_TIMESTAMP = 'NO_TIMESTAMP';

    public const string JOB_FAILED = 'JOB_FAILED';

    public const string AUDIT = 'signing.long_term_validation';

    public const string AUDIT_FAILED = 'signing.long_term_validation_failed';

    /** Why the last attempt did not succeed, in plain Swedish, for the envelope page. */
    public static function failureLabel(string $code): string
    {
        return match ($code) {
            self::FILE_CHANGED => __('den förseglade filen i lagringen stämde inte med sin kontrollsumma'),
            self::NO_TIMESTAMP => __('PDF-motorn hittade ingen ny tidsstämpel i filen'),
            self::JOB_FAILED => __('långtidsvalideringen gick inte att slutföra'),
            default => rtrim(PdfEngineFailure::tryFrom($code)?->message() ?? __('långtidsvalideringen gick inte att slutföra'), '.'),
        };
    }

    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * One step of long-term validation for one envelope, when one is due. Must run inside the company.
     *
     * @return string EXTENDED, FAILED (recorded on the envelope; the next night tries again) or NOTHING
     */
    public function attempt(string $envelopeId, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        if (! LongTermValidation::applies()) {
            return self::NOTHING;
        }

        $envelope = SigningEnvelope::query()->find($envelopeId);

        if (! $envelope instanceof SigningEnvelope || ! self::stillHasSigners($envelope->id)) {
            return self::NOTHING;
        }

        $step = match (true) {
            LongTermValidation::validationDataDue($envelope, $now) => self::STEP_VALIDATION_DATA,
            LongTermValidation::renewalDue($envelope, $now) => self::STEP_DOCUMENT_TIMESTAMP,
            default => null,
        };

        if ($step === null) {
            return self::NOTHING;
        }

        $current = self::newestFile($envelope->id);

        if (! $current instanceof SigningDocumentVersion) {
            return self::NOTHING;
        }

        try {
            $written = $this->extend($envelope, $current, $step);
        } catch (PdfEngineException $exception) {
            return $this->recordFailure($envelope->id, $exception->failure->value, $now);
        } catch (SealFileChanged) {
            return $this->recordFailure($envelope->id, self::FILE_CHANGED, $now);
        }

        try {
            return $this->finish($envelope->id, $current->id, $step, $written, $now);
        } catch (Throwable $exception) {
            // Nothing was committed: the file goes, unless a row holds it after all.
            self::deleteUnheld($written['path']);

            throw $exception;
        }
    }

    /** Records why an attempt did not succeed; audited only when the reason changes, so nightly retries do not fill the log. */
    public function recordFailure(string $envelopeId, string $code, ?CarbonImmutable $now = null): string
    {
        return DB::transaction(function () use ($envelopeId, $code, $now): string {
            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();

            if (! $envelope instanceof SigningEnvelope) {
                return self::NOTHING;
            }

            $code = mb_substr($code, 0, 30);
            $changed = $envelope->ltv_failure !== $code;
            $envelope->forceFill(['ltv_failure' => $code, 'ltv_attempted_at' => $now ?? CarbonImmutable::now()])->save();

            if ($changed) {
                Audit::record(self::AUDIT_FAILED, $envelope, ['envelope_id' => $envelope->id, 'failure' => $code], $envelope->company_id);
            }

            return self::FAILED;
        });
    }

    /** The newest file of a sealed envelope: its newest TIMESTAMPED version, else the SEALED one. */
    public static function newestFile(string $envelopeId): ?SigningDocumentVersion
    {
        return SigningDocumentVersion::query()
            ->where('envelope_id', $envelopeId)
            ->whereIn('kind', [SigningDocumentVersion::SEALED, SigningDocumentVersion::TIMESTAMPED])
            ->orderByDesc('id')
            ->first();
    }

    /** The directory of a company's long-term validation files, one directory per envelope inside it. */
    public static function directory(int $companyId): string
    {
        return 'crm-signing/'.$companyId.'/ltv';
    }

    /** The directory of one envelope's long-term validation files: deleted with the envelope (EnvelopeDeletion). */
    public static function envelopeDirectory(int $companyId, string $envelopeId): string
    {
        return self::directory($companyId).'/'.$envelopeId;
    }

    /**
     * A new, unique file in the envelope's directory: two attempts, or a retry, never write the same path, so no
     * attempt can overwrite or delete a file another one stored. A file an interrupted attempt left is removed with
     * the envelope, or by signing:prune-engine-work once it is a day old and no row holds it.
     */
    public static function newPath(int $companyId, string $envelopeId): string
    {
        return self::envelopeDirectory($companyId, $envelopeId).'/'.Str::uuid()->toString().'-tidsstampel.pdf';
    }

    /** Whether a path on the signing disk has the shape newPath() gives (the sweep touches nothing else). */
    public static function isLongTermFile(string $path): bool
    {
        return preg_match('#^crm-signing/\d+/ltv/[0-9a-f-]{36}/[0-9a-f-]{36}-tidsstampel\.pdf$#', $path) === 1;
    }

    /** The envelope id in a long-term validation path, or null when it is not one. */
    public static function envelopeOf(string $path): ?string
    {
        return self::isLongTermFile($path) ? explode('/', $path)[3] : null;
    }

    /** Deletes a file this class wrote, but never one a version row holds. Runs inside the company, whose directory the path is in. */
    private static function deleteUnheld(string $path): void
    {
        if (! SigningDocumentVersion::query()->where('path', $path)->exists()) {
            Storage::disk(SigningRequest::DISK)->delete($path);
        }
    }

    /** A pruned or erased envelope has lost its signers (and is deleted itself); its file is never extended. */
    private static function stillHasSigners(string $envelopeId): bool
    {
        return SigningRequest::query()->where('envelope_id', $envelopeId)->exists();
    }

    /**
     * The newest file, extended by the engine and stored on the signing disk. Outside any transaction.
     *
     * @return array{path: string, bytes: int, sha256: string, newest: ?TimestampFact}
     *
     * @throws PdfEngineException|SealFileChanged
     */
    private function extend(SigningEnvelope $envelope, SigningDocumentVersion $current, string $step): array
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $inputBytes = $disk->get($current->path);

        // The newest file must still be the stored one: read once and checked against its SHA-256.
        if (! is_string($inputBytes) || ! hash_equals($current->sha256, hash('sha256', $inputBytes))) {
            throw new SealFileChanged;
        }

        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-ltv-'.Str::uuid()->toString();
        $files = ['input' => "{$work}-input.pdf", 'output' => "{$work}-output.pdf"];

        try {
            file_put_contents($files['input'], $inputBytes);

            $result = $step === self::STEP_VALIDATION_DATA
                ? $this->engine->addValidationData($files['input'], $files['output'])
                : $this->engine->archiveTimestamp($files['input'], $files['output'], Sealing::timestampUrls(), LongTermValidation::mdAlgorithm());

            $bytes = (string) file_get_contents($result->path);

            // The engine checks it too: the previous file is the start of the new one, never rewritten.
            if (strlen($bytes) <= strlen($inputBytes) || ! str_starts_with($bytes, $inputBytes)) {
                throw new PdfEngineException(PdfEngineFailure::NotIncremental);
            }

            $newest = $this->newestTimestamp($result, $envelope, $step);
            $path = self::newPath($envelope->company_id, $envelope->id);
            $disk->put($path, $bytes);

            return ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'newest' => $newest];
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * The newest timestamp in the written file. After a document timestamp it must be a document timestamp newer
     * than the envelope's last one, or the renewal did not happen.
     *
     * @throws PdfEngineException
     */
    private function newestTimestamp(LongTermResult $result, SigningEnvelope $envelope, string $step): ?TimestampFact
    {
        $newest = $result->newest();

        if ($step === self::STEP_DOCUMENT_TIMESTAMP && (
            ! $newest instanceof TimestampFact
            || $newest->kind !== TimestampFact::DOCUMENT
            || ($envelope->last_timestamp_at !== null && ! $newest->at->greaterThan($envelope->last_timestamp_at))
        )) {
            throw new PdfEngineException(PdfEngineFailure::InvalidResponse, detail: self::NO_TIMESTAMP);
        }

        return $newest;
    }

    /**
     * @param  array{path: string, bytes: int, sha256: string, newest: ?TimestampFact}  $written
     */
    private function finish(string $envelopeId, int $inputVersionId, string $step, array $written, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($envelopeId, $inputVersionId, $step, $written, $now): string {
            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();

            // Deleted meanwhile (pruned, erased), out of retention, or another worker added a version first: this file is not used.
            if (! $envelope instanceof SigningEnvelope
                || $envelope->seal_status !== SealStatus::Sealed
                || ! LongTermValidation::withinRetention($envelope, $now)
                || self::newestFile($envelope->id)?->id !== $inputVersionId
                || ($step === self::STEP_VALIDATION_DATA && $envelope->ltv_level !== null)
            ) {
                DB::afterCommit(fn () => self::deleteUnheld($written['path']));

                return self::NOTHING;
            }

            DB::afterRollBack(fn () => self::deleteUnheld($written['path']));

            $version = SigningDocumentVersion::create([
                'envelope_id' => $envelope->id,
                'kind' => SigningDocumentVersion::TIMESTAMPED,
                'path' => $written['path'],
                'sha256' => $written['sha256'],
                'bytes' => $written['bytes'],
            ]);

            $newest = $written['newest'];
            $level = $step === self::STEP_DOCUMENT_TIMESTAMP ? SealLevel::B_LTA : ($envelope->ltv_level ?? SealLevel::B_LT);
            $attributes = ['ltv_level' => $level, 'ltv_failure' => null, 'ltv_attempted_at' => $now];

            if ($newest instanceof TimestampFact) {
                $newer = $envelope->last_timestamp_at === null || $newest->at->greaterThan($envelope->last_timestamp_at);

                // The validity follows the newest timestamp: set once after the seal, then only with a newer timestamp (trigger).
                if ($newer) {
                    $attributes['last_timestamp_at'] = $newest->at;
                }

                if ($newer || $envelope->timestamp_valid_until === null) {
                    $attributes['timestamp_valid_until'] = LongTermValidation::validUntil($newest);
                }
            }

            $envelope->forceFill($attributes)->save();

            // Ids, enums and dates only (principle 8).
            Audit::record(self::AUDIT, $envelope, [
                'envelope_id' => $envelope->id,
                'version_id' => $version->id,
                'step' => $step,
                'level' => $level->value,
                'timestamp_valid_until' => $envelope->timestamp_valid_until?->toIso8601String(),
            ], $envelope->company_id);

            return self::EXTENDED;
        });
    }
}
