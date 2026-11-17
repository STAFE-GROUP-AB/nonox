<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ProposalLoader;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A planner accepts all or some of a schedule proposal (ADR-037 amendment,
 * gap 3). Each accepted item gives its open shift the proposed person
 * through SaveShift (source PROPOSAL), so the shift becomes a draft and the
 * employee sees nothing until the period is published; every existing
 * check and audit applies. A proposal is accepted once.
 *
 * Refused as stale ("Schemat har ändrats …") when a shift in the period
 * changed after the proposal was made, an accepted shift is gone or no
 * longer open, or the accepted assignments no longer hold against today's
 * availability, absence, skills and working-time rules (the engine
 * re-checks them on freshly loaded data): an accepted proposal never adds
 * a warning. Audited with ids and counts only.
 */
final class AcceptProposal
{
    use GuardsPlanning;

    public function __construct(
        private readonly SaveShift $save,
        private readonly ProposalLoader $loader,
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
        $shifts = Shift::query()->whereKey($items->pluck('shift_id')->all())->get()->keyBy('id');

        if ($this->isStale($company, $proposal, $items, $shifts, $now)) {
            throw ValidationException::withMessages(['proposal' => self::staleMessage()]);
        }

        return DB::transaction(function () use ($user, $company, $proposal, $items, $shifts, $now): int {
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAccepted()) {
                throw ValidationException::withMessages(['proposal' => __('Förslaget är redan godkänt. Gör ett nytt förslag för passen som är kvar.')]);
            }

            foreach ($items as $item) {
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

        return ProposalEngine::verify($input, $items->mapWithKeys(fn (ProposalItem $item): array => [$item->shift_id => (int) $item->employee_id])->all()) !== [];
    }
}
