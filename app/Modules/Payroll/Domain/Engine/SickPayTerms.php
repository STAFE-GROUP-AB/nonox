<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * What another module adds to the sick-pay calculation (A8 in
 * docs/avtal/gap-analys.md), so the SickPay step needs no agreement or
 * schedule of its own:
 *
 * - averageWeeklySupplementsOre: supplements (OB) the employee is expected
 *   to earn in an average week. They are "anställningsförmåner" under
 *   sjuklönelagen (1991:1047) 6 §, so they count in the week the
 *   karensavdrag is a share of.
 * - averageWeeklyHours: the average weekly hours, for an employment with
 *   irregular hours and no weekly hours of its own (worked time before the
 *   sick period, or the published schedule when there is no such history).
 * - longSick: the agreement's deduction per calendar day after the
 *   employer's period (sjuklön dag 15–90 for salaried staff).
 * - hourValue: the agreement's own value of an hour of sick pay.
 * - karens: the agreement's karensavdrag counted in time.
 * - warnings: what the module could not express as data and the person
 *   running payroll should check, shown on the payslip.
 *
 * The lost supplements per sick day are on each AbsenceInput.
 */
final readonly class SickPayTerms
{
    /**
     * @param  array<string, mixed>  $explanation  where the averages come from (period, source), shown on the payslip line
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?int $averageWeeklySupplementsOre = null,
        public ?string $averageWeeklyHours = null,
        public ?LongSickTerms $longSick = null,
        public array $explanation = [],
        public ?SickHourValue $hourValue = null,
        public ?KarensTerms $karens = null,
        public array $warnings = [],
    ) {}
}
