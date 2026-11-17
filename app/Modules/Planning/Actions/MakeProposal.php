<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\Proposal\DemandBlock;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ProposalDemand;
use App\Modules\Planning\Support\ProposalLoader;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Makes a schedule proposal (ADR-037 amendment, gap 3): who the engine
 * proposes for the open shifts of a period of at most four weeks, from
 * today on, and, when "Föreslå nya pass" is on (v2, P8), which new shifts
 * would close the gap between the staffing demand and the plan within the
 * staff-cost budget (ProposalDemand) and who should work them. Saves "Vad
 * väger mest" and the new-shift settings when they changed, runs the engine
 * on data loaded in a fixed number of queries, and stores the proposal with
 * one item per open or new shift; a new shift that does not fit the budget
 * is stored without a person and named so. The schedule itself is not
 * touched. Audited with ids, dates, counts and the engine version only;
 * proposals older than 90 days are deleted.
 */
final class MakeProposal
{
    use GuardsPlanning;

    public const int MAX_DAYS = 28;

    public function __construct(
        private readonly ProposalLoader $loader,
        private readonly ModuleManager $modules,
        private readonly ProposalDemand $demand,
    ) {}

    /** @param  array<string, mixed>  $input  from, to (Y-m-d), and optionally weights, fairness_weeks, unsocial_from, unsocial_to, new_shifts, shift_hours ("4, 6, 8"), break_minutes */
    public function __invoke(User $user, Company $company, array $input): Proposal
    {
        $this->guard($user, $company);

        $levels = array_keys(ProposalWeights::LEVELS);
        $validated = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weights' => ['nullable', 'array'],
            ...collect(ProposalWeights::CRITERIA)->mapWithKeys(fn (string $criterion): array => ["weights.{$criterion}" => ['nullable', Rule::in($levels)]])->all(),
            'fairness_weeks' => ['nullable', 'integer', 'between:'.PlanningSettings::FAIRNESS_WEEKS[0].','.PlanningSettings::FAIRNESS_WEEKS[1]],
            'unsocial_from' => ['nullable', 'date_format:H:i'],
            'unsocial_to' => ['nullable', 'date_format:H:i'],
            'new_shifts' => ['nullable', 'boolean'],
            'shift_hours' => ['nullable', 'string', 'max:60'],
            'break_minutes' => ['nullable', 'integer', 'between:'.PlanningSettings::BREAK_MINUTES[0].','.PlanningSettings::BREAK_MINUTES[1]],
        ], [], [
            'from' => __('Från'),
            'to' => __('Till'),
            'fairness_weeks' => __('Veckor bakåt'),
            'unsocial_from' => __('Obekväm tid från'),
            'unsocial_to' => __('Obekväm tid till'),
            'break_minutes' => __('Rast i långa nya pass'),
        ])->validate();

        if (isset($validated['shift_hours']) && PlanningSettings::shiftHours($validated['shift_hours']) === []) {
            throw ValidationException::withMessages(['shift_hours' => __('Ange passlängderna i hela timmar mellan 2 och 10, till exempel 4, 6, 8.')]);
        }

        $from = CarbonImmutable::parse((string) $validated['from'], LocalTimestamp::ZONE)->startOfDay();
        $to = CarbonImmutable::parse((string) $validated['to'], LocalTimestamp::ZONE)->startOfDay();
        $now = CarbonImmutable::now(LocalTimestamp::ZONE);

        if ($from->lessThan($now->startOfDay())) {
            throw ValidationException::withMessages(['from' => __('Ett förslag kan bara gälla från och med idag.')]);
        }

        if ((int) round($from->diffInDays($to)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Ett förslag kan gälla högst fyra veckor.')]);
        }

        $this->saveParameters($user, $company, $validated);

        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $input = $this->loader->load($company, $from, $to, $now);
        $demand = PlanningSettings::bool($settings, 'proposal_new_shifts') ? $this->demand->blocks($company, $input, $now, $settings) : null;
        $blocks = $demand['blocks'] ?? [];
        $input = $this->loader->withBlocks($input, $blocks);
        $result = ProposalEngine::propose($input);

        return DB::transaction(function () use ($user, $company, $from, $to, $input, $result, $now, $settings, $demand, $blocks): Proposal {
            $parameters = [
                'weights' => $input->weights->levels,
                'fairness_weeks' => $input->fairnessWeeks,
                'unsocial_from' => $input->unsocialFrom,
                'unsocial_to' => $input->unsocialTo,
                'swaps_tried' => $result->swapsTried,
            ];

            if ($demand !== null) {
                $parameters += [
                    'new_shifts' => true,
                    'shift_hours' => array_map(fn (int $minutes): int => intdiv($minutes, 60), PlanningSettings::shiftLengths($settings)),
                    'break_minutes' => PlanningSettings::breakMinutes($settings),
                    'demand_scope' => $demand['scope'],
                    'new_count' => count($blocks),
                    'over_budget' => count($demand['over']),
                    'budget_unchecked' => $demand['unchecked'],
                    'estimates' => $demand['estimates'],
                    'unplaced' => $demand['unplaced'],
                    'cut' => $demand['cut'],
                    'company_ignored' => $demand['company_ignored'],
                ];
            }

            $proposal = Proposal::query()->create([
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'parameters' => $parameters,
                'engine_version' => ProposalEngine::VERSION,
                'slot_count' => count($result->outcomes) + count($demand['over'] ?? []),
                'filled_count' => $result->filled(),
                'swaps_kept' => $result->swapsKept,
                'created_by' => $user->id,
            ]);

            $rows = [];
            $slotStarts = [];

            foreach ($input->slots as $slot) {
                $slotStarts[$slot->id] = $slot->start->getTimestamp();
            }

            foreach ($result->outcomes as $outcome) {
                $block = self::block($outcome->slotId, $blocks);
                $reasons = $block === null ? $outcome->reasons : [['code' => 'covers_demand', 'hours' => $block->coveredHours], ...$outcome->reasons];
                $rows[] = [$slotStarts[$outcome->slotId] ?? 0, self::row($company, $proposal, $block, $outcome->slotId, $outcome->employeeId, $outcome->parts, $reasons, $now)];
            }

            foreach ($demand['over'] ?? [] as $block) {
                $rows[] = [$block->start->getTimestamp(), self::row($company, $proposal, $block, null, null, [], [['code' => 'covers_demand', 'hours' => $block->coveredHours], ['code' => 'over_budget']], $now)];
            }

            // Time order, keeping the engine's order for the same start: positions follow the day.
            $order = array_keys($rows);
            usort($order, fn (int $a, int $b): int => [$rows[$a][0], $a] <=> [$rows[$b][0], $b]);
            $rows = array_map(fn (int $index, int $position): array => [...$rows[$index][1], 'position' => $position], $order, array_keys($order));

            foreach (array_chunk($rows, 500) as $chunk) {
                ProposalItem::query()->insert($chunk);
            }

            Audit::record('planning.proposal_made', $proposal, [
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'slots' => $proposal->slot_count,
                'filled' => $proposal->filled_count,
                'swaps_kept' => $proposal->swaps_kept,
                'engine_version' => ProposalEngine::VERSION,
                ...($demand === null ? [] : ['new_shifts' => count($blocks), 'over_budget' => count($demand['over'])]),
            ], $company, $user);

            // Retention: proposals are personal data about how work is shared out, kept 90 days.
            Proposal::query()->where('created_at', '<', $now->subDays(Proposal::RETENTION_DAYS))->delete();

            return $proposal;
        });
    }

    /**
     * An item row for the insert: an open shift by its id, or a new shift
     * with its own times, break and area.
     *
     * @param  array<string, float>  $parts
     * @param  list<array<string, int|string>>  $reasons
     * @return array<string, mixed>
     */
    private static function row(Company $company, Proposal $proposal, ?DemandBlock $block, ?string $slotId, ?int $employeeId, array $parts, array $reasons, CarbonImmutable $now): array
    {
        return [
            'company_id' => $company->id,
            'proposal_id' => $proposal->id,
            'shift_id' => $block === null ? $slotId : null,
            'starts_at' => $block === null ? null : ShiftQuery::instant($block->start),
            'ends_at' => $block === null ? null : ShiftQuery::instant($block->end),
            'break_minutes' => $block?->breakMinutes,
            'area_id' => $block?->areaId,
            'employee_id' => $employeeId,
            'score_parts' => $parts === [] ? null : json_encode($parts, JSON_THROW_ON_ERROR),
            'reasons' => json_encode($reasons, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param  list<DemandBlock>  $blocks */
    private static function block(string $slotId, array $blocks): ?DemandBlock
    {
        if (! str_starts_with($slotId, ProposalLoader::NEW_SLOT)) {
            return null;
        }

        return $blocks[(int) substr($slotId, strlen(ProposalLoader::NEW_SLOT))] ?? null;
    }

    /**
     * Stores the weights, the window and the unsocial time when they were
     * given and differ from the stored ones (audited by the module settings).
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveParameters(User $user, Company $company, array $validated): void
    {
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $store = [];

        if (isset($validated['weights']) && is_array($validated['weights'])) {
            $weights = ProposalWeights::from([...ProposalWeights::from($settings['proposal_weights'] ?? null)->levels, ...array_filter($validated['weights'])])->levels;

            if ($weights !== ProposalWeights::from($settings['proposal_weights'] ?? null)->levels) {
                $store['proposal_weights'] = $weights;
            }
        }

        if (isset($validated['fairness_weeks']) && (int) $validated['fairness_weeks'] !== PlanningSettings::fairnessWeeks($settings)) {
            $store['fairness_weeks'] = (int) $validated['fairness_weeks'];
        }

        foreach (['unsocial_from', 'unsocial_to'] as $key) {
            if (isset($validated[$key]) && $validated[$key] !== PlanningSettings::time($settings, $key)) {
                $store[$key] = (string) $validated[$key];
            }
        }

        if (isset($validated['new_shifts']) && (bool) $validated['new_shifts'] !== PlanningSettings::bool($settings, 'proposal_new_shifts')) {
            $store['proposal_new_shifts'] = (bool) $validated['new_shifts'];
        }

        if (isset($validated['shift_hours'])) {
            $hours = PlanningSettings::shiftHours($validated['shift_hours']);

            if ($hours !== array_map(fn (int $minutes): int => intdiv($minutes, 60), PlanningSettings::shiftLengths($settings))) {
                $store['proposal_shift_hours'] = $hours;
            }
        }

        if (isset($validated['break_minutes']) && (int) $validated['break_minutes'] !== PlanningSettings::breakMinutes($settings)) {
            $store['proposal_break_minutes'] = (int) $validated['break_minutes'];
        }

        if ($store !== []) {
            $this->modules->updateSettings(PlanningModule::KEY, $company, $user, $store);
        }
    }
}
