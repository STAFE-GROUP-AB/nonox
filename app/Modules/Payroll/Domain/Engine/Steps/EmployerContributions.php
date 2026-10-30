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
 * reduced rate for older employees, and the temporary youth reduction up to
 * its monthly cap. Nothing is due while the year's basis stays below the
 * annual threshold.
 *
 * TODO(legal-verify): rounding of contributions per payslip (here half up
 * to öre; AGI reports totals), contributions owed for earlier months once
 * the annual threshold is passed, and regional support (not applied).
 */
final class EmployerContributions implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $basis = $draft->contributionBasis();
        $rules = $context->rules;
        $born = $context->birthDate->year;
        $fullBp = (int) ((array) $rules->get('employer_contribution.full'))['bp'];
        $threshold = (int) ((array) $rules->get('employer_contribution.min_annual_basis'))['ore'];
        $ytd = $context->accumulators['contribution_basis_ytd'] ?? 0;

        if ($basis <= 0 || $ytd + $basis < $threshold) {
            $draft->employerContributionOre = 0;
            $draft->contributionExplanation = ['basis_ore' => $basis, 'below_annual_threshold_ore' => $threshold];

            return;
        }

        foreach ((array) ((array) $rules->get('employer_contribution.reduced_by_birthyear'))['bands'] as $band) {
            $band = (array) $band;

            if ($born >= ($band['born_from'] ?? PHP_INT_MIN) && $born <= ($band['born_to'] ?? PHP_INT_MAX)) {
                $this->charge($draft, [[$basis, (int) $band['bp']]], ['rule' => 'reduced_by_birthyear', 'born' => $born]);

                return;
            }
        }

        $youth = (array) $rules->get('employer_contribution.youth_reduction');

        if (($youth['applies'] ?? false) && $born >= (int) $youth['born_from'] && $born <= (int) $youth['born_to']) {
            $capped = min($basis, (int) $youth['monthly_cap_ore']);
            $this->charge($draft, [[$capped, (int) $youth['bp']], [$basis - $capped, $fullBp]], ['rule' => 'youth_reduction', 'born' => $born, 'cap_ore' => (int) $youth['monthly_cap_ore']]);

            return;
        }

        $this->charge($draft, [[$basis, $fullBp]], ['rule' => 'full', 'born' => $born]);
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
