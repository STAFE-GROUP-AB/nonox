<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\AcceptProposal;
use App\Modules\Planning\Actions\MakeProposal;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ProposalDemand;
use App\Modules\Planning\Support\ShiftQuery;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planering → Schemaförslag (/planering/forslag, ADR-037 amendment, gap 3):
 * the planner picks a period and what weighs most, the engine proposes who
 * should work the open shifts and, with "Föreslå nya pass" on (v2, P8),
 * which new shifts the staffing demand needs within the budget and who
 * should work them; each proposed shift shows its reasons.
 * The planner accepts all or some; accepted shifts become drafts, nothing
 * is published. Planners only (planning:manage). A fixed number of queries
 * whatever the number of shifts and people.
 */
class ProposalPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'forslag')]
    public ?int $proposalId = null;

    /** @var array<string, string> */
    public array $weights = ProposalWeights::DEFAULTS;

    public int $fairnessWeeks = 8;

    public string $unsocialFrom = '19:00';

    public string $unsocialTo = '07:00';

    public bool $newShifts = false;

    public string $shiftHours = '4, 6, 8';

    public int $breakMinutes = 30;

    /** @var list<int|string> item ids ticked for acceptance */
    public array $selected = [];

    public function mount(ModuleManager $modules): void
    {
        abort_unless($this->allowed('planning:manage'), 403);

        $settings = PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $this->company()));
        $this->weights = ProposalWeights::from($settings['proposal_weights'] ?? null)->levels;
        $this->fairnessWeeks = PlanningSettings::fairnessWeeks($settings);
        $this->unsocialFrom = PlanningSettings::time($settings, 'unsocial_from');
        $this->unsocialTo = PlanningSettings::time($settings, 'unsocial_to');
        $this->newShifts = PlanningSettings::bool($settings, 'proposal_new_shifts');
        $this->shiftHours = implode(', ', array_map(fn (int $minutes): int => intdiv($minutes, 60), PlanningSettings::shiftLengths($settings)));
        $this->breakMinutes = PlanningSettings::breakMinutes($settings);

        $proposal = $this->proposal();

        if ($proposal instanceof Proposal) {
            $this->proposalId = $proposal->id;
            $this->from = $this->from !== '' ? $this->from : $proposal->period_from->toDateString();
            $this->to = $this->to !== '' ? $this->to : $proposal->period_to->toDateString();

            if (! $proposal->isAccepted()) {
                $this->selected = self::filledItems($proposal);
            }
        }

        if (! self::isDate($this->from) || ! self::isDate($this->to)) {
            $monday = CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->addWeek();
            $this->from = $monday->toDateString();
            $this->to = $monday->addDays(6)->toDateString();
        }
    }

    public function make(MakeProposal $make): void
    {
        try {
            $proposal = $make($this->user(), $this->company(), [
                'from' => $this->from,
                'to' => $this->to,
                'weights' => $this->weights,
                'fairness_weeks' => $this->fairnessWeeks,
                'unsocial_from' => $this->unsocialFrom,
                'unsocial_to' => $this->unsocialTo,
                'new_shifts' => $this->newShifts,
                'shift_hours' => $this->shiftHours,
                'break_minutes' => $this->breakMinutes,
            ]);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->proposalId = $proposal->id;
        $this->selected = self::filledItems($proposal);

        $new = (int) ($proposal->parameters['new_count'] ?? 0) + (int) ($proposal->parameters['over_budget'] ?? 0);

        $this->toast(match (true) {
            $proposal->slot_count === 0 && ($proposal->parameters['new_shifts'] ?? false) === true => __('Det finns inga öppna pass och inget behov som saknar pass i perioden.'),
            $proposal->slot_count === 0 => __('Det finns inga öppna pass att fylla i perioden. Lägg till öppna pass i schemat först.'),
            $new > 0 => __('Förslaget är klart: :filled av :total pass har ett förslag, varav :new nya pass utifrån bemanningsbehovet. Inget i schemat är ändrat förrän du godkänner.', ['filled' => $proposal->filled_count, 'total' => $proposal->slot_count, 'new' => $new]),
            default => __('Förslaget är klart: :filled av :total öppna pass har ett förslag. Inget i schemat är ändrat förrän du godkänner.', ['filled' => $proposal->filled_count, 'total' => $proposal->slot_count]),
        });
    }

    public function acceptSelected(AcceptProposal $accept): void
    {
        $this->accept($accept, array_map('intval', $this->selected));
    }

    public function acceptAll(AcceptProposal $accept): void
    {
        $this->accept($accept, null);
    }

    /** @param  ?list<int>  $itemIds */
    private function accept(AcceptProposal $accept, ?array $itemIds): void
    {
        $proposal = $this->proposal();

        if (! $proposal instanceof Proposal) {
            return;
        }

        try {
            $count = $accept($this->user(), $this->company(), $proposal, $itemIds);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->selected = [];
        $this->toast(trans_choice('{1} 1 pass har fått en person som utkast. Publicera schemat när du är nöjd.|[2,*] :count pass har fått en person som utkast. Publicera schemat när du är nöjd.', $count, ['count' => $count]));
    }

    public function render(): View
    {
        $proposal = $this->proposal();
        $items = collect();
        $shifts = collect();
        $people = collect();
        $stale = false;

        if ($proposal instanceof Proposal) {
            $items = ProposalItem::query()->where('proposal_id', $proposal->id)->orderBy('position')->get();
            $shifts = Shift::query()->whereKey($items->pluck('shift_id')->filter()->values()->all())->get(['id', 'starts_at', 'ends_at', 'break_minutes', 'area_id', 'skill_id', 'employee_id', 'status'])->keyBy('id');
            $people = Employee::query()->whereKey($items->pluck('employee_id')->filter()->unique()->values()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $now = CarbonImmutable::now(LocalTimestamp::ZONE);
            // A new shift with a person whose start has passed cannot be accepted: it would be created in the past.
            $started = $items->contains(fn (ProposalItem $item): bool => $item->isNew() && $item->employee_id !== null && $item->starts_at !== null && $item->starts_at->lessThanOrEqualTo($now));
            $stale = ! $proposal->isAccepted() && ($started || $items->count() !== $proposal->slot_count || ShiftQuery::startingOn($proposal->period_from, $proposal->period_to)->where('updated_at', '>', $proposal->created_at)->exists());
        }

        return view('planning::livewire.proposals', [
            'proposal' => $proposal,
            'days' => $items
                ->sortBy(fn (ProposalItem $item): array => [self::startOf($item, $shifts)?->getTimestamp() ?? PHP_INT_MAX, $item->position])
                ->groupBy(fn (ProposalItem $item): string => self::startOf($item, $shifts)?->toDateString() ?? ''),
            'shifts' => $shifts,
            'people' => $people,
            'areas' => Area::query()->pluck('name', 'id'),
            'skills' => Skill::query()->pluck('name', 'id'),
            'stale' => $stale,
            'criteria' => ProposalWeights::CRITERIA,
            'levels' => array_keys(ProposalWeights::LEVELS),
            'breakAfter' => ProposalDemand::maxWithoutBreak((array) PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company()))['working_time_rules']),
        ]);
    }

    /** The proposal shown: the one in the address, else the latest. */
    private function proposal(): ?Proposal
    {
        return $this->proposalId !== null
            ? Proposal::query()->findOrFail($this->proposalId)
            : Proposal::query()->latest('id')->first();
    }

    /** @return list<int> the items with a proposed person, all ticked by default */
    private static function filledItems(Proposal $proposal): array
    {
        return array_values(ProposalItem::query()->where('proposal_id', $proposal->id)->whereNotNull('employee_id')->orderBy('position')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * When the item's shift starts: a new shift's own time, else its open shift's.
     *
     * @param  Collection<string, Shift>  $shifts
     */
    private static function startOf(ProposalItem $item, Collection $shifts): ?CarbonImmutable
    {
        return $item->isNew() ? $item->starts_at : ($item->shift_id === null ? null : $shifts->get($item->shift_id)?->starts_at);
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
