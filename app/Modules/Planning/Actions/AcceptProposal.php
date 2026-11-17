<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\Proposal\DemandBlock;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Domain\Proposal\ProposalInput;
use App\Modules\Planning\Domain\Proposal\ProposalSlot;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ProposalDemand;
use App\Modules\Planning\Support\ProposalLoader;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A planner accepts all or some of a schedule proposal (ADR-037 amendment,
 * gap 3). Each accepted item gives its open shift the proposed person, or,
 * for a new shift made from the demand (v2, P8), creates the shift with the
 * person, through SaveShift (source PROPOSAL), so the shift is a draft and
 * the employee sees nothing until the period is published; every existing
 * check and audit applies. A proposal is accepted once.
 *
 * Refused as stale ("Schemat har ändrats …") when a shift in the period
 * changed after the proposal was made, an accepted shift is gone or no
 * longer open, or the accepted assignments no longer hold against today's
 * availability, absence, skills and working-time rules (the engine
 * re-checks them on freshly loaded data, with the proposal and its shifts
 * locked): an accepted proposal never breaks a hard rule (mertid is only
 * shown). Audited with ids and counts only.
 */
final class AcceptProposal
{
    use GuardsPlanning;

    /** The first key of the transaction-scoped advisory lock taken per company while a proposal is accepted. */
    public const int LOCK_NAMESPACE = 37_007;

    public function __construct(
        private readonly SaveShift $save,
        private readonly ProposalLoader $loader,
        private readonly ProposalDemand $demand,
        private readonly ModuleManager $modules,
    ) {}

    /** @param  ?list<int>  $itemIds  the items to accept; null accepts every item with a proposed person */
    public function __invoke(User $user, Company $company, Proposal $proposal, ?array $itemIds = null): int
    {
        $this->guard($user, $company);

        if ($proposal->isAccepted()) {
            throw ValidationException::withMessages(['proposal' => __('Förslaget är redan godkänt. Gör ett nytt förslag för passen som är kvar.')]);
        }

        $items = ProposalItem::query()->where('proposal_id', $proposal->id)->whereNotNull('employee_id')->orderBy('position')->get();

        if ($itemIds !== null) {
            $wanted = array_map('intval', $itemIds);
            $items = $items->filter(fn (ProposalItem $item): bool => in_array($item->id, $wanted, true))->values();
        }

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => __('Markera minst ett pass med ett förslag.')]);
        }

        $now = CarbonImmutable::now(LocalTimestamp::ZONE);

        return DB::transaction(function () use ($user, $company, $proposal, $items, $now): int {
            // One acceptance per company at a time: two proposals with different shifts for the same person must not both pass the re-check.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $company->id]);
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAccepted()) {
                throw ValidationException::withMessages(['proposal' => __('Förslaget är redan godkänt. Gör ett nytt förslag för passen som är kvar.')]);
            }

            // The shifts are locked before the checks, so nobody can give them to someone else between the check and the save.
            $shifts = Shift::query()->whereKey($items->pluck('shift_id')->filter()->values()->all())->lockForUpdate()->get()->keyBy('id');

            if ($this->isStale($company, $locked, $items, $shifts, $now)) {
                throw ValidationException::withMessages(['proposal' => self::staleMessage()]);
            }

            foreach ($items as $item) {
                if ($item->isNew()) {
                    $created = ($this->save)($user, $company, [
                        'employee_id' => $item->employee_id,
                        'area_id' => $item->area_id,
                        'starts_at' => $item->starts_at?->toIso8601String(),
                        'ends_at' => $item->ends_at?->toIso8601String(),
                        'break_minutes' => (int) $item->break_minutes,
                    ], null, 'PROPOSAL');
                    // The item points at the shift it became, so the proposal shows what was made.
                    ProposalItem::query()->whereKey($item->id)->update(['shift_id' => $created->id]);

                    continue;
                }

                /** @var Shift $shift */
                $shift = $shifts->get($item->shift_id);

                ($this->save)($user, $company, [
                    'employee_id' => $item->employee_id,
                    'area_id' => $shift->area_id,
                    'skill_id' => $shift->skill_id,
                    'starts_at' => $shift->starts_at->toIso8601String(),
                    'ends_at' => $shift->ends_at->toIso8601String(),
                    'break_minutes' => $shift->break_minutes,
                    'note' => $shift->note,
                ], $shift, 'PROPOSAL');
            }

            ProposalItem::query()->whereKey($items->pluck('id')->all())->update(['accepted_at' => $now, 'updated_at' => $now]);
            $locked->forceFill(['accepted_at' => $now, 'accepted_by' => $user->id, 'accepted_count' => $items->count()])->save();

            Audit::record('planning.proposal_accepted', $locked, [
                'accepted' => $items->count(),
                'filled' => $locked->filled_count,
                'items' => $items->pluck('id')->all(),
            ], $company, $user);

            return $items->count();
        });
    }

    public static function staleMessage(): string
    {
        return __('Schemat har ändrats sedan förslaget gjordes. Gör ett nytt förslag.');
    }

    /**
     * Whether the schedule changed under the proposal. Four queries and the
     * loader's fixed set.
     *
     * @param  Collection<int, ProposalItem>  $items
     * @param  Collection<string, Shift>  $shifts
     */
    public function isStale(Company $company, Proposal $proposal, Collection $items, Collection $shifts, CarbonImmutable $now): bool
    {
        $changed = ShiftQuery::startingOn($proposal->period_from, $proposal->period_to)
            ->where('updated_at', '>', $proposal->created_at)
            ->exists();

        if ($changed || ProposalItem::query()->where('proposal_id', $proposal->id)->count() !== $proposal->slot_count) {
            return true;
        }

        foreach ($items as $item) {
            if ($item->isNew()) {
                // A new shift whose start has passed would be created in the past.
                if ($item->starts_at === null || $item->starts_at->lessThanOrEqualTo($now)) {
                    return true;
                }

                continue;
            }

            $shift = $shifts->get($item->shift_id);

            if (! $shift instanceof Shift || $shift->employee_id !== null || $shift->status === Shift::CANCELLED) {
                return true;
            }
        }

        $parameters = $proposal->parameters;
        $input = $this->loader->load(
            $company,
            $proposal->period_from,
            $proposal->period_to,
            $now,
            ProposalWeights::from($parameters['weights'] ?? null),
            is_numeric($parameters['fairness_weeks'] ?? null) ? (int) $parameters['fairness_weeks'] : null,
        );

        // The accepted new shifts are slots again, checked on today's data like the open ones.
        $new = $items->filter(fn (ProposalItem $item): bool => $item->isNew())->values();
        $blocks = array_values($new->map(fn (ProposalItem $item): DemandBlock => new DemandBlock(
            $item->starts_at ?? $now,
            $item->ends_at ?? $now,
            (int) $item->break_minutes,
            $item->area_id,
            ($item->starts_at ?? $now)->toDateString(),
            (int) ($item->reasons[0]['hours'] ?? 0),
        ))->all());
        $input = $this->loader->withBlocks($input, $blocks);
        $assignments = [];

        foreach ($items as $item) {
            $key = $item->isNew() ? ProposalLoader::NEW_SLOT.$new->search(fn (ProposalItem $candidate): bool => $candidate->id === $item->id) : (string) $item->shift_id;
            $assignments[$key] = (int) $item->employee_id;
        }

        if (ProposalEngine::verify($input, $assignments) !== []) {
            return true;
        }

        if ($blocks === []) {
            return false;
        }

        // The new shifts must still fit today's budget, with the accepted open shifts taking their room first: a budget lowered since is not passed.
        $accepted = $items->reject(fn (ProposalItem $item): bool => $item->isNew())->pluck('shift_id')->map(fn (mixed $id): string => (string) $id)->all();
        $reserved = new ProposalInput(
            slots: array_values(array_filter($input->slots, fn (ProposalSlot $slot): bool => in_array($slot->id, $accepted, true))),
            candidates: [],
            periodFrom: $input->periodFrom,
            periodTo: $input->periodTo,
        );
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));

        return $this->demand->fit($company, $reserved, $blocks, $settings)['over'] !== [];
    }
}
