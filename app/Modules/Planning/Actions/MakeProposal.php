<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ProposalLoader;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Makes a schedule proposal (ADR-037 amendment, gap 3): who the engine
 * proposes for the open shifts of a period of at most four weeks, from
 * today on. Saves "Vad väger mest" when it changed, runs the engine on data
 * loaded in a fixed number of queries, and stores the proposal with one
 * item per open shift. The schedule itself is not touched. Audited with
 * ids, dates, counts and the engine version only; proposals older than 90
 * days are deleted.
 */
final class MakeProposal
{
    use GuardsPlanning;

    public const int MAX_DAYS = 28;

    public function __construct(
        private readonly ProposalLoader $loader,
        private readonly ModuleManager $modules,
    ) {}

    /** @param  array<string, mixed>  $input  from, to (Y-m-d), and optionally weights, fairness_weeks, unsocial_from, unsocial_to */
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
        ], [], [
            'from' => __('Från'),
            'to' => __('Till'),
            'fairness_weeks' => __('Veckor bakåt'),
            'unsocial_from' => __('Obekväm tid från'),
            'unsocial_to' => __('Obekväm tid till'),
        ])->validate();

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

        $input = $this->loader->load($company, $from, $to, $now);
        $result = ProposalEngine::propose($input);

        return DB::transaction(function () use ($user, $company, $from, $to, $input, $result, $now): Proposal {
            $proposal = Proposal::query()->create([
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'parameters' => [
                    'weights' => $input->weights->levels,
                    'fairness_weeks' => $input->fairnessWeeks,
                    'unsocial_from' => $input->unsocialFrom,
                    'unsocial_to' => $input->unsocialTo,
                    'swaps_tried' => $result->swapsTried,
                ],
                'engine_version' => ProposalEngine::VERSION,
                'slot_count' => count($result->outcomes),
                'filled_count' => $result->filled(),
                'swaps_kept' => $result->swapsKept,
                'created_by' => $user->id,
            ]);

            $rows = [];

            foreach ($result->outcomes as $position => $outcome) {
                $rows[] = [
                    'company_id' => $company->id,
                    'proposal_id' => $proposal->id,
                    'shift_id' => $outcome->slotId,
                    'employee_id' => $outcome->employeeId,
                    'position' => $position,
                    'score_parts' => $outcome->parts === [] ? null : json_encode($outcome->parts, JSON_THROW_ON_ERROR),
                    'reasons' => json_encode($outcome->reasons, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

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
            ], $company, $user);

            // Retention: proposals are personal data about how work is shared out, kept 90 days.
            Proposal::query()->where('created_at', '<', $now->subDays(Proposal::RETENTION_DAYS))->delete();

            return $proposal;
        });
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

        if ($store !== []) {
            $this->modules->updateSettings(PlanningModule::KEY, $company, $user, $store);
        }
    }
}
