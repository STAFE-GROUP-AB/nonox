<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\SidecarPdfEngine;
use App\Domain\Signing\Pdf\TimestampFact;
use App\Domain\Signing\Retention\SigningRetention;
use App\Jobs\TimestampSigningEnvelope;
use App\Models\SigningEnvelope;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The rules of long-term validation (ADR-046 amendment, gap 5, package S11).
 *
 * After a seal with a signature timestamp (B-T), the validation data of the
 * seal and its timestamp (a DSS with VRI entries: certificates, OCSP answers,
 * CRLs) is added to the file: PAdES B-LT. The nightly signing:timestamp adds
 * a document timestamp (with the validation data of everything before it) to
 * a file whose newest timestamp stops being reliable within the configured
 * months: PAdES B-LTA, renewed again before that one expires. Each file is a
 * new TIMESTAMPED row in signing_document_versions and starts with the exact
 * bytes of the one before, so every earlier copy still verifies.
 *
 * Inert unless sealing is switched on (signing.seal_enabled, off on the hosted
 * service until the eIDAS trust-service question is answered), the engine is
 * installed and timestamp servers are configured. A B-B seal (sealed without
 * a timestamp) is never extended: B-LT presupposes B-T.
 *
 * Retention: only an envelope within its retention period (retention_until
 * today or later) or under a legal hold is extended, and an existing timestamp
 * that already outlives the retention period is not renewed. Extending never
 * moves retention_until: the timestamp serves the period the envelope already
 * has, it is never a reason to keep the file longer. An envelope that was
 * deleted (pruned or erased) has no row and no file, and its signers' rows
 * must still exist.
 *
 * TODO(legal-verify): the trust-service question of gap 5 covers this too
 * (eIDAS art. 3.16: the preservation of seals and the creation of time
 * stamps), and so does whether keeping a file "within retention" may justify
 * renewing it during a legal hold that outlasts retention.
 */
final class LongTermValidation
{
    public const int DEFAULT_RENEW_MONTHS = 12;

    public const int MAX_RENEW_MONTHS = 60;

    /** Days after sealing during which validation data that was not available is tried every night; weekly after. */
    public const int DAILY_RETRY_DAYS = 7;

    /** Hours before an attempt that did not succeed is tried again (validation data in the first week, renewals). */
    public const int RETRY_HOURS = 20;

    /** Envelopes queued per night and kind; the rest come the next night. */
    public const int BATCH = 500;

    /** Whether long-term validation runs on this installation at all. */
    public static function applies(): bool
    {
        return Sealing::switchedOn() && app(PdfEngine::class)->isInstalled() && Sealing::timestampUrls() !== [];
    }

    /** Months before the newest timestamp stops being reliable that a new document timestamp is added (1–60, default 12). */
    public static function renewMonths(): int
    {
        return max(1, min(self::MAX_RENEW_MONTHS, (int) config('nonox.signing.ltv.renew_months', self::DEFAULT_RENEW_MONTHS)));
    }

    /** The hash algorithm of new document timestamps. */
    public static function mdAlgorithm(): string
    {
        $algorithm = strtolower((string) config('nonox.signing.ltv.md_algorithm', 'sha256'));

        return in_array($algorithm, SidecarPdfEngine::MD_ALGORITHMS, true) ? $algorithm : 'sha256';
    }

    /**
     * Until when a timestamp can be relied on: its TSA certificate's expiry, or earlier the end of the day the
     * installation set for its hash algorithm (nonox.signing.ltv.algorithm_valid_until), Stockholm time.
     */
    public static function validUntil(TimestampFact $fact): CarbonImmutable
    {
        $dates = (array) config('nonox.signing.ltv.algorithm_valid_until', []);
        $date = $dates[strtolower($fact->mdAlgorithm)] ?? null;
        $until = $fact->certificateNotAfter;

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $algorithmEnd = CarbonImmutable::parse($date, SigningRetention::ZONE)->endOfDay()->utc();
            $until = $algorithmEnd->lessThan($until) ? $algorithmEnd : $until;
        }

        return $until;
    }

    /**
     * Whether the envelope may be extended today: within its retention period or under a legal hold. A completed
     * envelope always has a retention date (S16); one without is not extended.
     */
    public static function withinRetention(SigningEnvelope $envelope, ?CarbonImmutable $now = null): bool
    {
        if ($envelope->retention_hold) {
            return true;
        }

        $today = ($now ?? CarbonImmutable::now())->setTimezone(SigningRetention::ZONE)->startOfDay();

        return $envelope->retention_until !== null && ! $envelope->retention_until->lessThan($today);
    }

    /** Whether a new document timestamp is due: the newest one stops being reliable within renewMonths and before the retention period ends. */
    public static function renewalDue(SigningEnvelope $envelope, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($envelope->ltv_level === null || $envelope->timestamp_valid_until === null || ! self::withinRetention($envelope, $now)) {
            return false;
        }

        if ($envelope->timestamp_valid_until->greaterThan($now->addMonthsNoOverflow(self::renewMonths()))) {
            return false;
        }

        // A renewal that did not succeed is tried again the next night, not at once.
        if ($envelope->ltv_attempted_at !== null && $envelope->ltv_attempted_at->greaterThan($now->subHours(self::RETRY_HOURS))) {
            return false;
        }

        // A timestamp that is reliable past the last day the file is kept needs no successor (never a reason to keep it longer).
        return $envelope->retention_hold
            || $envelope->retention_until === null
            || ! $envelope->timestamp_valid_until->greaterThan(CarbonImmutable::parse($envelope->retention_until->toDateString(), SigningRetention::ZONE)->endOfDay());
    }

    /** Whether the validation data of a B-T seal is still to be added (B-LT), and it is time to try again. */
    public static function validationDataDue(SigningEnvelope $envelope, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($envelope->seal_status !== SealStatus::Sealed || $envelope->seal_level !== SealLevel::B_T || $envelope->ltv_level !== null || ! self::withinRetention($envelope, $now)) {
            return false;
        }

        if ($envelope->ltv_attempted_at === null) {
            return true;
        }

        $recent = $envelope->sealed_at !== null && $envelope->sealed_at->greaterThan($now->subDays(self::DAILY_RETRY_DAYS));

        return $envelope->ltv_attempted_at->lessThanOrEqualTo($recent ? $now->subHours(self::RETRY_HOURS) : $now->subDays(7));
    }

    /**
     * The envelopes of every company that are due tonight, ids only, at most BATCH of each kind. Across companies on
     * purpose (ADR-046 S11): each job runs inside its own company and checks again under a lock. Every condition of
     * validationDataDue() and renewalDue() is in the query, so envelopes that keep failing (tried within the retry
     * rhythm) are not selected and never crowd out others; the least recently tried come first.
     *
     * @return Collection<int, SigningEnvelope>
     */
    public static function due(?CarbonImmutable $now = null, int $batch = self::BATCH): Collection
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(SigningRetention::ZONE)->toDateString();
        $columns = ['id', 'company_id', 'seal_status', 'seal_level', 'sealed_at', 'ltv_level', 'ltv_attempted_at', 'timestamp_valid_until', 'retention_until', 'retention_hold'];
        $withinRetention = fn (Builder $query) => $query
            ->where(fn (Builder $retention) => $retention->where('retention_hold', true)->orWhere('retention_until', '>=', $today))
            // A revoked certificate stays revoked: not tried again every night.
            ->where(fn (Builder $failure) => $failure->whereNull('ltv_failure')->orWhere('ltv_failure', '<>', PdfEngineFailure::CertificateRevoked->value));
        $recentlySealed = $now->subDays(self::DAILY_RETRY_DAYS);
        $retryAfter = $now->subHours(self::RETRY_HOURS);
        $weeklyAfter = $now->subDays(7);

        return CompanyScope::bypass(function () use ($now, $batch, $columns, $withinRetention, $recentlySealed, $retryAfter, $weeklyAfter): Collection {
            $validationData = SigningEnvelope::query()
                ->where('seal_status', SealStatus::Sealed->value)
                ->where('seal_level', SealLevel::B_T->value)
                ->whereNull('ltv_level')
                ->where($withinRetention)
                ->where(fn (Builder $query) => $query
                    ->whereNull('ltv_attempted_at')
                    ->orWhere(fn (Builder $recent) => $recent->where('sealed_at', '>', $recentlySealed)->where('ltv_attempted_at', '<=', $retryAfter))
                    ->orWhere('ltv_attempted_at', '<=', $weeklyAfter))
                // Static SQL: NULLS FIRST has no query-builder form.
                ->orderByRaw('ltv_attempted_at ASC NULLS FIRST')
                ->orderBy('sealed_at')
                ->limit($batch)
                ->get($columns);

            $renewals = SigningEnvelope::query()
                ->whereNotNull('ltv_level')
                ->where('timestamp_valid_until', '<=', $now->addMonthsNoOverflow(self::renewMonths()))
                ->where($withinRetention)
                ->where(fn (Builder $query) => $query->whereNull('ltv_attempted_at')->orWhere('ltv_attempted_at', '<=', $retryAfter))
                // A timestamp reliable past the last day the file is kept needs no successor, unless the file is held.
                // Static SQL: the Stockholm date of a timestamptz against a date column.
                ->where(fn (Builder $query) => $query->where('retention_hold', true)->orWhereRaw("(timestamp_valid_until AT TIME ZONE 'Europe/Stockholm')::date <= retention_until"))
                ->orderByRaw('ltv_attempted_at ASC NULLS FIRST')
                ->orderBy('timestamp_valid_until')
                ->limit($batch)
                ->get($columns);

            return $validationData->concat($renewals)->values();
        });
    }

    /**
     * Queues the long-term validation of one envelope when it applies. Never throws: it runs after a seal or from the
     * nightly command, and the next night tries again.
     */
    public static function queue(int $companyId, string $envelopeId): void
    {
        try {
            if (self::applies()) {
                TimestampSigningEnvelope::dispatch($companyId, $envelopeId);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
