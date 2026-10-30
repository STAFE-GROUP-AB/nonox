<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * The last day to declare a payment month, from the agi.deadlines rule of
 * the month after: Skatteverket's list of dates for companies with a
 * turnover up to 40 million kronor, or a fixed day for larger ones, moved
 * to the next working day.
 *
 * TODO(legal-verify): the declaration day for companies with a turnover
 * above 40 million kronor.
 */
final class AgiDeadlines
{
    public const string SMALL = 'TURNOVER_UP_TO_40_MSEK';

    public const string LARGE = 'TURNOVER_OVER_40_MSEK';

    public static function for(string $period, string $group): ?CarbonImmutable
    {
        try {
            $rule = (array) RuleSet::for(self::declarationMonth($period))->get('agi.deadlines');
        } catch (MissingPayrollRuleException) {
            return null;
        }

        return self::fromRule($rule, $period, $group);
    }

    /** The month the payment month is declared in; its rule decides the day. */
    public static function declarationMonth(string $period): CarbonImmutable
    {
        return CarbonImmutable::parse("{$period}-01")->addMonthNoOverflow();
    }

    /**
     * The deadline from an agi.deadlines rule value already loaded, for
     * pages that look at many companies at once.
     *
     * @param  array<mixed>  $rule
     */
    public static function fromRule(array $rule, string $period, string $group): ?CarbonImmutable
    {
        $next = self::declarationMonth($period);

        if ($group === self::LARGE && isset($rule['turnover_over_40_msek_declare_day'])) {
            $day = $next->setDay((int) $rule['turnover_over_40_msek_declare_day']);

            while ($day->isWeekend() || SwedishHolidays::isHoliday($day)) {
                $day = $day->addDay();
            }

            return $day;
        }

        foreach ((array) ($rule['turnover_up_to_40_msek'] ?? []) as $date) {
            $deadline = CarbonImmutable::parse((string) $date);

            if ($deadline->format('Y-m') === $next->format('Y-m')) {
                return $deadline;
            }
        }

        return null;
    }
}
