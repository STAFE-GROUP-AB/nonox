<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Planning\Domain\Proposal\BudgetFit;
use App\Modules\Planning\Domain\Proposal\DemandBlock;
use App\Modules\Planning\Domain\Proposal\DemandBlocks;
use App\Modules\Planning\Domain\Proposal\ProposalInput;
use App\Modules\Planning\Domain\Proposal\ProposalSlot;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\Budget\BudgetData;
use App\Modules\Planning\Support\Demand\DemandData;
use Carbon\CarbonImmutable;

/**
 * The new shifts a schedule proposal makes (v2, ADR-037 amendment gap 3,
 * P8): the gap between the staffing demand (gap 1) and the plan as shifts
 * of the company's allowed lengths (DemandBlocks), kept within the
 * staff-cost budget (gap 2, BudgetFit). A fixed number of queries whatever
 * the number of areas, days, shifts or budgets: the active areas, the
 * demand of every scope at once, the budget weeks, and one aggregate of the
 * planned shifts' estimated cost per worked hour.
 *
 * Scopes: the areas that have a staffing target of their own, each getting
 * shifts in that area; only when no area has one, the whole company's
 * target, with shifts without an area. Both at once would count the same
 * need twice.
 *
 * The cost figure is the shifts' own estimate (cost_estimate_ore), summed
 * per area: never a person's wage, and only used to decide how many new
 * shifts fit the budget, never who gets one.
 */
final class ProposalDemand
{
    /** Minutes in a row without a break when the company's rule has no value: arbetstidslagen 15 §. */
    public const int MAX_WITHOUT_BREAK = 300;

    public function __construct(
        private readonly DemandData $demand,
        private readonly BudgetData $budgets,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  resolved planning settings
     * @return array{blocks: list<DemandBlock>, over: list<DemandBlock>, unplaced: array<string, int>, cut: array<string, int>, unchecked: bool, estimates: array<string, int>, scope: string, company_ignored: bool}
     */
    public function blocks(Company $company, ProposalInput $input, CarbonImmutable $now, array $settings): array
    {
        $from = $input->periodFrom;
        $to = $input->periodTo;
        $areas = Area::query()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get(['id', 'cost_center_id']);
        $demand = $this->demand->forScopes($company, $from, $to, $areas, $settings);
        $areaScopes = array_filter($demand, fn (int|string $scope): bool => $scope !== 'company', ARRAY_FILTER_USE_KEY);
        $scopes = $areaScopes !== [] ? $areaScopes : array_intersect_key($demand, ['company' => true]);

        // Turning the rule off only silences the warning: a new shift still gets its breaks. TODO(legal-verify): arbetstidslagen (1982:673) 15 §, at most five hours in a row without a break, and whether an agreement may deviate.
        $maxWithoutBreak = self::maxWithoutBreak($input->rules);
        $lengths = PlanningSettings::shiftLengths($settings);
        $break = PlanningSettings::breakMinutes($settings);
        $blocks = [];
        $unplaced = [];
        $cut = [];

        foreach ($scopes as $scope => $days) {
            foreach ($days as $date => $day) {
                [$made, $left, $stopped] = DemandBlocks::forDay($day, $scope === 'company' ? null : (int) $scope, $lengths, $break, $maxWithoutBreak, $now);
                array_push($blocks, ...$made);

                if ($left > 0) {
                    $unplaced[$date] = ($unplaced[$date] ?? 0) + $left;
                }

                if ($stopped > 0) {
                    $cut[$date] = ($cut[$date] ?? 0) + $stopped;
                }
            }
        }

        usort($blocks, fn (DemandBlock $a, DemandBlock $b): int => [$a->start->getTimestamp(), $a->areaId ?? 0, $a->end->getTimestamp()] <=> [$b->start->getTimestamp(), $b->areaId ?? 0, $b->end->getTimestamp()]);
        ksort($unplaced);
        ksort($cut);
        $fit = $blocks === [] ? ['kept' => [], 'over' => [], 'unchecked' => false, 'estimates' => []] : $this->fit($company, $input, $blocks, $settings);

        return [
            'blocks' => $fit['kept'],
            'over' => $fit['over'],
            'unplaced' => $unplaced,
            'cut' => $cut,
            'unchecked' => $fit['unchecked'],
            'estimates' => $fit['estimates'],
            'scope' => $areaScopes !== [] ? 'areas' : 'company',
            // The whole company's target is left out when areas have their own: it would count the same need twice.
            'company_ignored' => $areaScopes !== [] && isset($demand['company']),
        ];
    }

    /**
     * Minutes in a row without a break for new shifts: the company's rule
     * value, also when the rule is switched off, else 300.
     *
     * @param  array<string, mixed>  $rules  resolved working_time_rules
     */
    public static function maxWithoutBreak(array $rules): int
    {
        $rule = $rules['max_without_break'] ?? null;

        return is_array($rule) && is_numeric($rule['value'] ?? null) && (int) $rule['value'] > 0 ? (int) $rule['value'] : self::MAX_WITHOUT_BREAK;
    }

    /**
     * The new shifts against today's budget, with the input's open shifts
     * taking their room first: when a proposal is made, and again when it is
     * accepted, so a budget lowered since then is not passed. Four queries
     * at most (the budget weeks and the cost aggregate).
     *
     * @param  list<DemandBlock>  $blocks
     * @param  array<string, mixed>  $settings
     * @return array{kept: list<DemandBlock>, over: list<DemandBlock>, unchecked: bool, estimates: array<string, int>}
     */
    public function fit(Company $company, ProposalInput $input, array $blocks, array $settings): array
    {
        $from = $input->periodFrom;
        $to = $input->periodTo;
        $mondays = [];

        for ($monday = $from->startOfWeek(CarbonImmutable::MONDAY); $monday->lessThanOrEqualTo($to); $monday = $monday->addWeek()) {
            $mondays[] = $monday;
        }

        [$areaRates, $companyRate] = $this->rates($from->subWeeks(PlanningSettings::fairnessWeeks($settings)), $to);
        $reserved = array_map(fn (ProposalSlot $slot): array => [
            'area' => $slot->areaId,
            'date' => $slot->start->setTimezone(LocalTimestamp::ZONE)->toDateString(),
            'minutes' => $slot->workedMinutes(),
        ], $input->slots);

        return BudgetFit::apply($blocks, $this->budgets->forCompany($company, $mondays), $areaRates, $companyRate, $reserved);
    }

    /**
     * The estimated cost per worked hour of the shifts planned with a person
     * from $from to $to, per area and for the whole company, in one
     * aggregate query: the shifts' own estimates, never a wage.
     *
     * @return array{array<int, int>, ?int} area id => öre per hour, and the whole company's
     */
    private function rates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = ShiftQuery::startingOn($from, $to)
            ->whereNotNull('employee_id')
            ->whereNotNull('cost_estimate_ore')
            ->where('status', '<>', Shift::CANCELLED)
            ->groupBy('area_id')
            ->selectRaw('area_id, SUM(cost_estimate_ore) AS cost, SUM(GREATEST(0, EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60 - break_minutes)) AS minutes')
            ->toBase()
            ->get();

        $areas = [];
        $cost = 0;
        $minutes = 0;

        foreach ($rows as $row) {
            $rowCost = (int) $row->cost;
            $rowMinutes = (int) round((float) $row->minutes);
            $cost += $rowCost;
            $minutes += $rowMinutes;

            if ($row->area_id !== null && $rowMinutes > 0) {
                $areas[(int) $row->area_id] = intdiv($rowCost * 60 + intdiv($rowMinutes, 2), $rowMinutes);
            }
        }

        return [$areas, $minutes > 0 ? intdiv($cost * 60 + intdiv($minutes, 2), $minutes) : null];
    }
}
