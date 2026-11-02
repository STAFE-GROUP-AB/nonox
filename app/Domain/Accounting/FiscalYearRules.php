<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Company\LegalForm;
use Carbon\CarbonImmutable;

/**
 * Rules for the length and boundaries of a räkenskapsår (BFL 3 kap. 1-3 §§).
 *
 * Enligt AI-utredning 2026-11-02, juristgranskning återstår. Source: BFL
 * (SFS 1999:1078) 3 kap., https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
 * read 2026-11-02. AGENTS.md §6.2 still says "6-18 months"; the law sets no
 * minimum (see docs/adr/ADR-010-reports-vat-year-end.md).
 */
final class FiscalYearRules
{
    /**
     * When the bookkeeping obligation begins or the fiscal year is changed,
     * the year may be shorter than twelve months (no lower limit) or extended
     * to at most eighteen; it may also be cut short when the obligation ends
     * (BFL 3 kap. 3 §).
     *
     * TODO(legal-verify): enligt AI-utredning 2026-11-02, juristgranskning återstår.
     */
    public const int MAX_MONTHS = 18;

    public const int NORMAL_MONTHS = 12;

    /**
     * The fiscal year that contains the given date, for a company whose year
     * starts on the first day of $startMonth.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function yearContaining(CarbonImmutable $date, int $startMonth): array
    {
        $start = $date->startOfMonth()->month($startMonth);

        if ($start->greaterThan($date)) {
            $start = $start->subYear();
        }

        return [$start, $start->addMonths(self::NORMAL_MONTHS)->subDay()];
    }

    /**
     * Natural persons must use the calendar year (BFL 3 kap. 1 § second
     * paragraph). A sole trader (enskild firma) is always one.
     */
    public static function requiresCalendarYear(LegalForm $legalForm): bool
    {
        return $legalForm === LegalForm::Ef;
    }

    /**
     * A handelsbolag must use the calendar year when a natural person is
     * taxed for all or part of its income (BFL 3 kap. 1 § second paragraph).
     * NONOX does not know the partners, so the user has to confirm it.
     *
     * TODO(legal-verify): a kommanditbolag is treated as a handelsbolag here
     * (lag (1980:1102) om handelsbolag och enkla bolag 1 kap. 2 §).
     */
    public static function requiresCalendarYearWithNaturalPartner(LegalForm $legalForm): bool
    {
        return in_array($legalForm, [LegalForm::Hb, LegalForm::Kb], true);
    }

    /**
     * The year that follows one ending the day before $start. A company
     * that must use the calendar year gets a shortened year up to the next
     * 31 December when its earlier years were broken (a change of fiscal
     * year, BFL 3 kap. 3 §); after that, whole calendar years.
     *
     * TODO(legal-verify): confirm that a change to the calendar year needs
     * no permission from Skatteverket.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function nextYear(CarbonImmutable $start, bool $calendarYear): array
    {
        if ($calendarYear) {
            return [$start, $start->endOfYear()->startOfDay()];
        }

        return [$start, $start->addMonths(self::NORMAL_MONTHS)->subDay()];
    }

    public static function isCalendarAligned(CarbonImmutable $end): bool
    {
        return $end->month === 12 && $end->day === 31;
    }

    public static function months(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round($start->diffInMonths($end->addDay()));
    }

    /**
     * Returns a Swedish explanation of what is wrong, or null when the
     * period is a valid fiscal year.
     */
    public static function problem(CarbonImmutable $start, CarbonImmutable $end, bool $mayDeviate, bool $calendarYear = false): ?string
    {
        if ($start->day !== 1) {
            return __('Räkenskapsåret måste börja den första dagen i en månad.');
        }

        if (! $end->isSameDay($end->endOfMonth())) {
            return __('Räkenskapsåret måste sluta den sista dagen i en månad.');
        }

        if ($end->lessThanOrEqualTo($start)) {
            return __('Slutdatumet måste komma efter startdatumet.');
        }

        $months = self::months($start, $end);

        if (! $mayDeviate && $months !== self::NORMAL_MONTHS) {
            return __('Ett räkenskapsår ska vara tolv månader.');
        }

        if ($months > self::MAX_MONTHS) {
            return __('Ett räkenskapsår får vara högst :max månader.', ['max' => self::MAX_MONTHS]);
        }

        if ($calendarYear && ! self::isCalendarAligned($end)) {
            return __('En enskild firma ska ha kalenderår som räkenskapsår. Räkenskapsåret ska sluta den 31 december.');
        }

        return null;
    }
}
