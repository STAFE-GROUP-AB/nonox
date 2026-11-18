<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Document;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * The retention rule of a company document (ADR-081 gap 9). The rule's date
 * is the floor: retain_until is never below it, and a chosen "keep until"
 * date can raise it by at most MAX_EXTRA_YEARS and lower it again down to
 * the rule (GDPR art. 5.1 e). For räkenskapsinformation the floor is also
 * computed by the documents trigger from the fiscal year.
 */
final class DocumentRetention
{
    public const int MAX_CONTRACT_YEARS = 30;

    /** A chosen date may lie at most this many years after the rule (or after today, when the class has no rule). */
    public const int MAX_EXTRA_YEARS = 30;

    /**
     * The last day the rule keeps the document, or null when the rule sets
     * none (OTHER and PERSONNEL, a running contract that is not
     * räkenskapsinformation).
     */
    public static function ruleDate(RetentionClass $class, ?FiscalYear $fiscalYear, ?CarbonImmutable $contractEndsOn, ?int $contractYears, bool $accountingRecord = false): ?CarbonImmutable
    {
        return match ($class) {
            // BFL 7 kap. 2 §: seven years after the end of the calendar year in which the fiscal year ended.
            RetentionClass::Accounting => $fiscalYear === null ? null : self::accountingKeptUntil($fiscalYear),
            // A contract that is räkenskapsinformation (BFL 1 kap. 2 § första stycket 6) keeps at least the seven years.
            // TODO(legal-verify): which fiscal year a contract that runs over several years belongs to (proposed: the year it was entered into).
            RetentionClass::Contract => self::later(
                $contractEndsOn?->addYears($contractYears ?? 0),
                $accountingRecord && $fiscalYear !== null ? self::accountingKeptUntil($fiscalYear) : null,
            ),
            // TODO(legal-verify): whether some personnel documents have a statutory minimum period (GDPR art. 5.1 e; see lagringstider.md).
            RetentionClass::Personnel, RetentionClass::Other => null,
        };
    }

    public static function accountingKeptUntil(FiscalYear $fiscalYear): CarbonImmutable
    {
        return CarbonImmutable::createFromDate($fiscalYear->end_date->year + 7, 12, 31)->startOfDay();
    }

    /** The later of the rule's date and the chosen date. */
    public static function retainUntil(?CarbonImmutable $ruleDate, ?CarbonImmutable $keepUntil): ?CarbonImmutable
    {
        return self::later($ruleDate, $keepUntil);
    }

    /** The latest "keep until" date that may be chosen. */
    public static function maxKeepUntil(?CarbonImmutable $ruleDate, ?CarbonImmutable $today = null): CarbonImmutable
    {
        return ($ruleDate ?? $today ?? CarbonImmutable::today())->addYears(self::MAX_EXTRA_YEARS);
    }

    /** The floor the rule gives a stored document, with its fiscal year. */
    public static function floor(Document $document): ?CarbonImmutable
    {
        $fiscalYear = $document->fiscal_year_id === null ? null : FiscalYear::query()->find($document->fiscal_year_id);

        return self::ruleDate($document->retention_class, $fiscalYear, $document->contract_ends_on, $document->contract_retention_years, $document->is_accounting_record);
    }

    /**
     * The seven-year floor of räkenskapsinformation (class ACCOUNTING, or a
     * contract marked as räkenskapsinformation), or null for any other
     * document. The same as documents_accounting_floor() in the database.
     */
    public static function accountingFloor(RetentionClass $class, bool $accountingRecord, ?int $fiscalYearId): ?CarbonImmutable
    {
        if ($fiscalYearId === null || ($class !== RetentionClass::Accounting && ! $accountingRecord)) {
            return null;
        }

        $fiscalYear = FiscalYear::query()->find($fiscalYearId);

        return $fiscalYear instanceof FiscalYear ? self::accountingKeptUntil($fiscalYear) : null;
    }

    /**
     * Whether a change from the old state to the new keeps the old floor: while
     * the old floor applies, the new state must have a floor at least as late
     * and a retain_until on or after it. Otherwise räkenskapsinformation could
     * be reclassified first and deleted after (two steps).
     */
    public static function keepsFloor(?CarbonImmutable $oldFloor, ?CarbonImmutable $newFloor, ?CarbonImmutable $newRetainUntil, ?CarbonImmutable $today = null): bool
    {
        if ($oldFloor === null || $oldFloor->lessThan(($today ?? CarbonImmutable::today())->startOfDay())) {
            return true;
        }

        return $newFloor !== null && $newFloor->greaterThanOrEqualTo($oldFloor)
            && $newRetainUntil !== null && $newRetainUntil->greaterThanOrEqualTo($oldFloor);
    }

    /**
     * Why the document may not be deleted today, in Swedish, or null when it
     * may be.
     */
    public static function blocker(Document $document, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();

        if ($document->legal_hold) {
            return __('Dokumentet har bevarandespärr och kan inte raderas förrän spärren hävs.');
        }

        if ($document->retain_until !== null && $document->retain_until->greaterThanOrEqualTo($today->startOfDay())) {
            return $document->retention_class === RetentionClass::Accounting || $document->is_accounting_record
                ? __('Dokumentet är räkenskapsinformation och ska sparas till och med :date (bokföringslagen 7 kap. 2 §). Det kan raderas från :next.', ['date' => $document->retain_until->toDateString(), 'next' => $document->retain_until->addDay()->toDateString()])
                : __('Dokumentet ska sparas till och med :date. Det kan raderas från :next.', ['date' => $document->retain_until->toDateString(), 'next' => $document->retain_until->addDay()->toDateString()]);
        }

        if ($document->retention_class === RetentionClass::Contract && $document->contract_ends_on === null) {
            return __('Avtalet löper tills vidare. Ange när det upphör innan dokumentet kan raderas.');
        }

        return null;
    }

    /** The first day the document may be deleted, when its retention sets one. */
    public static function deletableFrom(Document $document): ?CarbonImmutable
    {
        return $document->retain_until?->addDay();
    }

    private static function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $b->greaterThan($a) ? $b : $a;
    }
}
