<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Step 11: employer contributions on the contribution basis, by the
 * employee's birth year (rules employer_contribution.*): full rate, the
 * reduced rate for older employees, and the temporary youth reduction.
 *
 * Pay is avgiftsfri while the year's pay to the person stays below the
 * annual threshold (SAL 2 kap. 14 §). The payslip that takes the year to
 * the threshold also carries the earlier avgiftsfri pay of the year in its
 * basis (SAL 2 kap. 24 § fjärde stycket), kept in the accumulator
 * contribution_free_basis_ytd (enligt granskning 2026-11-02,
 * juristgranskning återstår, AUD-LON-02, #71).
 *
 * The youth reduction covers at most the monthly cap of the pay paid out in
 * a calendar month (lag 2026:100 2 § andra stycket; SAL 2 kap. 24 § första
 * stycket), over all the person's payslips paid in that month: the
 * accumulator youth_reduced_basis_mMM holds what earlier payslips paid in
 * the month used (AUD-LON-03, #70).
 *
 * The accumulators a payslip read are kept in its explanation
 * (accumulators_read) when they could change its result, so attesting a run
 * after another run for the same person was attested stops and asks for a
 * new calculation (PayrollRuns::approve).
 *
 * Regional support (lag (2001:1170) 5 §; enligt granskning 2026-11-02,
 * juristgranskning återstår, AUD-LON-14, #78): when the company has chosen
 * it, 10 % of the contribution basis is deducted from the contributions,
 * at most the monthly cap for the whole company (Skatteverket: "högst
 * 7 100 kronor per månad"; per redovisningsperiod in AGI), never below the
 * ålderspensionsavgift, and not for a person who was 67 or older at the
 * start of the year (Skatteverket: "vid årets ingång är 67 år eller
 * äldre") or who only pays ålderspensionsavgift (2 §).
 *
 * TODO(legal-verify): rounding of contributions per payslip (here half up
 * to öre; AGI reports totals); regional support per payslip in öre with the
 * floor per payslip, where the law speaks of the period's total; the
 * company setting covers all employees (one fast driftställe in the area).
 */
final class EmployerContributions implements Step
{
    public const string BASIS_YTD = 'contribution_basis_ytd';

    public const string FREE_BASIS_YTD = 'contribution_free_basis_ytd';

    public static function youthKey(int $month): string
    {
        return sprintf('youth_reduced_basis_m%02d', $month);
    }

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $basis = $draft->contributionBasis();
        $rules = $context->rules;
        $born = $context->birthDate->year;
        $fullBp = (int) ((array) $rules->get('employer_contribution.full'))['bp'];
        $threshold = (int) ((array) $rules->get('employer_contribution.min_annual_basis'))['ore'];
        $ytd = $context->accumulators[self::BASIS_YTD] ?? 0;
        $earlierFree = max(0, $context->accumulators[self::FREE_BASIS_YTD] ?? 0);
        // Only while the year is below the threshold can another payslip change whether this one is avgiftsfri.
        $read = $ytd < $threshold ? [self::BASIS_YTD => $ytd, self::FREE_BASIS_YTD => $earlierFree] : [];

        if ($basis <= 0 || $ytd + $basis < $threshold) {
            $draft->employerContributionOre = 0;
            $draft->contributionExplanation = ['basis_ore' => $basis, 'below_annual_threshold_ore' => $threshold, 'accumulators_read' => $read];

            if ($basis > 0) {
                $this->delta($draft, self::FREE_BASIS_YTD, $basis);
            }

            return;
        }

        $explanation = ['accumulators_read' => $read];

        if ($earlierFree > 0) {
            // The earlier avgiftsfri pay of the year becomes part of this month's basis, and is no longer avgiftsfri.
            $explanation['earlier_free_basis_ore'] = $earlierFree;
            $basis += $earlierFree;
            $this->delta($draft, self::FREE_BASIS_YTD, -$earlierFree);
        }

        foreach ((array) ((array) $rules->get('employer_contribution.reduced_by_birthyear'))['bands'] as $band) {
            $band = (array) $band;

            if ($born >= ($band['born_from'] ?? PHP_INT_MIN) && $born <= ($band['born_to'] ?? PHP_INT_MAX)) {
                $this->charge($draft, [[$basis, (int) $band['bp']]], [...$explanation, 'rule' => 'reduced_by_birthyear', 'born' => $born]);

                return;
            }
        }

        $youth = (array) $rules->get('employer_contribution.youth_reduction');

        if (($youth['applies'] ?? false) && $born >= (int) $youth['born_from'] && $born <= (int) $youth['born_to']) {
            $key = self::youthKey($context->paymentDate->month);
            $used = max(0, $context->accumulators[$key] ?? 0);
            $cap = (int) $youth['monthly_cap_ore'];
            $capped = min($basis, max(0, $cap - $used));
            $this->delta($draft, $key, $capped);
            $explanation['accumulators_read'] = [...$read, $key => $used];
            $this->charge($draft, [[$capped, (int) $youth['bp']], [$basis - $capped, $fullBp]], [...$explanation, 'rule' => 'youth_reduction', 'born' => $born, 'cap_ore' => $cap, 'cap_used_before_ore' => $used]);
            $this->regionalSupport($context, $draft, $basis);

            return;
        }

        $this->charge($draft, [[$basis, $fullBp]], [...$explanation, 'rule' => 'full', 'born' => $born]);
        $this->regionalSupport($context, $draft, $basis);
    }

    /**
     * Lag (2001:1170) 5 §: 10 % of the basis off the contributions, within
     * the company's monthly cap and not below the ålderspensionsavgift.
     */
    private function regionalSupport(PayrollContext $context, PayslipDraft $draft, int $basis): void
    {
        if (! (bool) ($context->settings['regional_support'] ?? false) || $basis <= 0) {
            return;
        }

        $rule = (array) $context->rules->get('employer_contribution.regional_support');
        $yearStart = $context->paymentDate->startOfYear();
        $ageAtYearStart = $yearStart->year - $context->birthDate->year - ($context->birthDate->format('md') > $yearStart->format('md') ? 1 : 0);

        if ($ageAtYearStart >= (int) $rule['not_from_age']) {
            $draft->contributionExplanation = [...$draft->contributionExplanation, 'regional_support' => ['excluded' => 'age', 'age_at_year_start' => $ageAtYearStart]];

            return;
        }

        $cap = (int) $rule['monthly_cap_ore'];
        $used = max(0, $context->regionalSupportUsedOre);
        $floor = BigDecimal::of($basis)->multipliedBy((int) $rule['floor_bp'])->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        $tenPercent = BigDecimal::of($basis)->multipliedBy((int) $rule['deduction_bp'])->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        $deduction = max(0, min($tenPercent, $cap - $used, $draft->employerContributionOre - $floor));

        $draft->employerContributionOre -= $deduction;
        $draft->contributionExplanation = [...$draft->contributionExplanation, 'regional_support' => [
            'basis_ore' => $basis,
            'deduction_ore' => $deduction,
            'cap_ore' => $cap,
            'used_before_ore' => $used,
            'other_runs_ore' => $context->regionalSupportOtherRunsOre,
            'floor_ore' => $floor,
            'rule' => $context->rules->version('employer_contribution.regional_support')->id,
        ]];
    }

    private function delta(PayslipDraft $draft, string $key, int $amount): void
    {
        $draft->accumulatorDeltas[$key] = ($draft->accumulatorDeltas[$key] ?? 0) + $amount;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $parts  [basis öre, rate bp]
     * @param  array<string, mixed>  $explanation
     */
    private function charge(PayslipDraft $draft, array $parts, array $explanation): void
    {
        $total = 0;

        foreach ($parts as [$basis, $bp]) {
            $total += BigDecimal::of($basis)->multipliedBy($bp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        }

        $draft->employerContributionOre = $total;
        $draft->contributionExplanation = [...$explanation, 'parts' => array_map(fn (array $part): array => ['basis_ore' => $part[0], 'rate_bp' => $part[1]], $parts)];
    }
}
