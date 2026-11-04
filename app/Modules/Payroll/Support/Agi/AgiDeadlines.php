<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

use App\Domain\Deadlines\AgiDates;
use App\Models\Company;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use Carbon\CarbonImmutable;

/**
 * The last day to declare, and to pay, a payment month (docs/foretagsguiden/
 * datum/REGLER.md §2), from the law, so every year has a date:
 *
 * - Not VAT registered, or a VAT base up to 40 million kronor: declare and
 *   pay by the 12th of the month after, the 17th in January and August (SFL
 *   26 kap. 26 och 32 §§, 62 kap. 3 § första stycket).
 * - A VAT base above 40 million kronor (only a VAT-registered company can
 *   be large): declare by the 26th, the 27th in December (26 kap. 30 §), but
 *   pay by the 12th, the 17th in January (62 kap. 3 § andra stycket).
 *
 * A day that falls on a weekend, a holiday or one of the three eves moves
 * to the next weekday (lag (1930:173) 2 §, Workdays). Skatteverket's yearly
 * list (the agi.deadlines rule) is used for the declaration day when it has
 * the month, as a cross-check that a test keeps equal to the law. Not
 * handled: a decision under 26 kap. 31 § (the 12th for a large employer
 * whose payments are mismanaged).
 */
final class AgiDeadlines
{
    public const string SMALL = 'TURNOVER_UP_TO_40_MSEK';

    public const string LARGE = 'TURNOVER_OVER_40_MSEK';

    /** The company's group, from the company's own VAT and turnover settings (AgiDates::isLarge). */
    public static function groupFor(Company $company): string
    {
        return AgiDates::isLarge($company) ? self::LARGE : self::SMALL;
    }

    public static function for(string $period, string $group): CarbonImmutable
    {
        try {
            $rule = (array) RuleSet::for(self::declarationMonth($period))->get('agi.deadlines');
        } catch (MissingPayrollRuleException) {
            return self::lawDate($period, $group);
        }

        return self::fromRule($rule, $period, $group);
    }

    /** The month the payment month is declared in; its rule decides the day. */
    public static function declarationMonth(string $period): CarbonImmutable
    {
        return CarbonImmutable::parse("{$period}-01")->addMonthNoOverflow();
    }

    /**
     * The declaration day from an agi.deadlines rule value already loaded,
     * for pages that look at many companies at once: the published day for
     * a small employer when the list has the month, otherwise the law's.
     *
     * @param  array<mixed>  $rule
     */
    public static function fromRule(array $rule, string $period, string $group): CarbonImmutable
    {
        if ($group === self::SMALL) {
            $next = self::declarationMonth($period);

            foreach ((array) ($rule['turnover_up_to_40_msek'] ?? []) as $date) {
                $deadline = CarbonImmutable::parse((string) $date);

                if ($deadline->format('Y-m') === $next->format('Y-m')) {
                    return $deadline;
                }
            }
        }

        return self::lawDate($period, $group);
    }

    /** The declaration day by the law (SFL 26 kap. 26, 30 och 32 §§), moved to a weekday. */
    public static function lawDate(string $period, string $group): CarbonImmutable
    {
        return AgiDates::declaration($period, $group === self::LARGE);
    }

    /**
     * The last day to pay the tax withheld and the employer contributions:
     * the declaration day for a small employer, the 12th (the 17th in
     * January) for a large one (SFL 62 kap. 3 §).
     */
    public static function paymentDate(string $period, string $group): CarbonImmutable
    {
        return AgiDates::payment($period, $group === self::LARGE);
    }

    /**
     * Skatteverket's published declaration days for small employers in a
     * year, from the payroll rules data (the months' order), for the
     * cross-check with the law.
     *
     * @return list<string> Y-m-d
     */
    public static function publishedList(int $year): array
    {
        $file = dirname(__DIR__, 2)."/database/data/{$year}/rules.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        foreach ((array) (is_array($data) ? ($data['rules'] ?? $data) : []) as $rule) {
            if (is_array($rule) && ($rule['key'] ?? null) === 'agi.deadlines') {
                return array_values(array_map('strval', (array) ($rule['value']['turnover_up_to_40_msek'] ?? [])));
            }
        }

        return [];
    }
}
