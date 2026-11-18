<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\RecordGroupEntry;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\EliminationType;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Domain\Group\GroupLedger;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\FiscalYear;
use App\Models\GroupEntry;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SpansCompanies;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag → Elimineringar (ADR-060, addendum 2026-11-07, 3.2, S2-1.5;
 * ADR-081 E25): the elimination suggestions of a shared group for a
 * consolidation period with both sides and any difference, acceptance into
 * the group ledger, manual entries, reversals and the group ledger itself.
 * Per-company figures, so FULL access only; writing needs administration
 * of the group. What is shown is decided by GroupAccess on every render and
 * every action. Thin: every write goes through RecordGroupEntry.
 */
class EliminationsPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    #[Url(as: 'koncern', except: '')]
    public string $group = '';

    #[Url(as: 'fran', except: '')]
    public string $from = '';

    #[Url(as: 'till', except: '')]
    public string $to = '';

    /**
     * The fingerprint of every suggestion as last shown, by key: an
     * acceptance is bound to what the person saw, never to what the browser
     * sends back.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $seen = [];

    public string $manualType = 'OTHER';

    public string $manualReason = '';

    /** @var list<array{company: string, account: string, debit: string, credit: string}> */
    public array $manualRows = [];

    public ?int $reversing = null;

    public string $reverseReason = '';

    public function mount(): void
    {
        $groups = $this->groups();

        if ($this->group !== '' && $groups->firstWhere('id', (int) $this->group) === null) {
            // A group the user does not see answers 404, exactly like one that does not exist.
            abort(404);
        }

        $this->group = $this->group !== '' ? $this->group : (string) ($groups->first()->id ?? '');

        if ($this->from === '' || $this->to === '') {
            $this->defaultPeriod();
        }

        $this->resetManual();
    }

    public function selectGroup(string $group): void
    {
        if ($this->groups()->firstWhere('id', (int) $group) === null) {
            abort(404);
        }

        $this->group = $group;
        $this->reset('seen', 'reversing', 'reverseReason');
        $this->defaultPeriod();
        $this->resetManual();
    }

    public function accept(string $key, RecordGroupEntry $record): void
    {
        $this->attempt(function () use ($key, $record): void {
            $entry = $record->accept($this->user(), (int) $this->group, [
                'from' => $this->from,
                'to' => $this->to,
                'key' => $key,
                'fingerprint' => $this->seen[$key] ?? '',
            ]);
            $this->toast(__('Elimineringen är godkänd som post :number i koncernjournalen. Den dras från nästa konsolidering av perioden.', ['number' => $entry->number]));
        });
    }

    public function addRow(): void
    {
        if (count($this->manualRows) < RecordGroupEntry::MAX_ROWS) {
            $this->manualRows = [...$this->manualRows, ['company' => '', 'account' => '', 'debit' => '', 'credit' => '']];
        }
    }

    public function removeRow(int $index): void
    {
        $this->manualRows = array_values(array_filter($this->manualRows, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function saveManual(RecordGroupEntry $record): void
    {
        $this->attempt(function () use ($record): void {
            $entry = $record->manual($this->user(), (int) $this->group, [
                'from' => $this->from,
                'to' => $this->to,
                'type' => $this->manualType,
                'reason' => $this->manualReason,
                'rows' => array_values(array_filter($this->manualRows, fn (array $row): bool => trim($row['debit'].$row['credit']) !== '' || $row['account'] !== '')),
            ]);
            $this->resetManual();
            $this->toast(__('Posten är sparad som nummer :number i koncernjournalen. Den dras från nästa konsolidering av perioden.', ['number' => $entry->number]));
        });
    }

    public function startReverse(int $entryId): void
    {
        $this->reversing = $entryId;
        $this->reverseReason = '';
    }

    public function cancelReverse(): void
    {
        $this->reset('reversing', 'reverseReason');
    }

    public function reverse(RecordGroupEntry $record): void
    {
        if ($this->reversing === null) {
            return;
        }

        $this->attempt(function () use ($record): void {
            $entry = $record->reverse($this->user(), (int) $this->group, (int) $this->reversing, ['reason' => $this->reverseReason]);
            $this->reset('reversing', 'reverseReason');
            $this->toast(__('Posten är återförd med post :number. Båda finns kvar i koncernjournalen.', ['number' => $entry->number]));
        });
    }

    public function verifyLedger(): void
    {
        $this->attempt(function (): void {
            $access = GroupAccess::for($this->user());
            $group = $access->corporateGroup((int) $this->group);
            [$from] = $this->period();

            if (ConsolidateGroup::level($access, $group, $from) !== GroupAccessLevel::Full) {
                throw new AuthorizationException(__('Koncernjournalen kan bara kontrolleras av den som får se rapporter i alla koncernens företag.'));
            }

            $result = GroupLedger::verify($group);

            $result['intact']
                ? $this->toast(trans_choice('{0} Koncernjournalen är tom.|{1} Koncernjournalen stämmer: en post, utan luckor och med oförändrade kontrollsummor.|[2,*] Koncernjournalen stämmer: :count poster, utan luckor och med oförändrade kontrollsummor.', $result['entries'], ['count' => $result['entries']]))
                : $this->toast(__('Koncernjournalen stämmer inte för post :numbers. Kontakta den som driver NONOX.', ['numbers' => implode(', ', $result['broken'])]), 'error');
        });
    }

    public function render(EliminationSuggestions $engine): View
    {
        $access = GroupAccess::for($this->user());
        $groups = $this->groups($access);
        $current = $groups->firstWhere('id', (int) $this->group);

        if ($this->group !== '' && $current === null) {
            // Access ended while the page was open: shown as if the group did not exist.
            $this->reset('group', 'seen');
            $this->toast(__('Koncernen visas inte längre. Du kan ha förlorat behörigheten i ett av företagen.'), 'error');
        }

        $level = GroupAccessLevel::None;
        $suggestions = [];
        $entries = new Collection;
        $error = null;
        $administers = false;
        $names = [];
        $companies = [];
        $visible = [];
        [$from, $to] = $this->period();

        if ($current instanceof CorporateGroup) {
            $level = ConsolidateGroup::level($access, $current, $from);
        }

        if ($current instanceof CorporateGroup && $level === GroupAccessLevel::Full && $from !== null && $to !== null) {
            $administers = $access->administers($current);

            try {
                $result = $engine->compute($access, $current, $from, $to);
                $suggestions = $result['suggestions'];
                $companies = $result['companies'];
            } catch (ValidationException $exception) {
                $error = (string) collect($exception->errors())->flatten()->first();
            }

            $entries = GroupEntry::query()
                ->where('corporate_group_id', $current->id)
                ->whereDate('period_from', $from->toDateString())
                ->whereDate('period_to', $to->toDateString())
                ->with(['rows', 'acceptor:id,name', 'reversedBy:id,number,reverses_id'])
                ->orderBy('number')
                ->get();

            // Each company on an entry's rows is checked: a former member's entries show only their number (cold review E25, P1-2).
            $visible = RecordGroupEntry::visibleCompanies($access, $entries->flatMap(fn (GroupEntry $entry) => $entry->rows->pluck('company_id'))->all());
            $ids = array_unique([...$companies, ...array_keys(array_filter($visible))]);
            $names = $ids === [] ? [] : Company::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        $this->seen = array_column($suggestions, 'fingerprint', 'key');

        return view('livewire.group.eliminations-page', [
            'groups' => $groups,
            'current' => $current,
            'level' => $level,
            'suggestions' => $suggestions,
            'entries' => $entries,
            'error' => $error,
            'administers' => $administers,
            'names' => $names,
            'companies' => $companies,
            'visible' => $visible,
            'types' => EliminationType::cases(),
            'matched' => EliminationSuggestions::MATCHED,
            'needsAnalysis' => EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS,
            'needsReview' => EliminationSuggestions::NEEDS_REVIEW,
            'nothing' => EliminationSuggestions::NOTHING,
        ]);
    }

    /** @return Collection<int, CorporateGroup> the groups the user sees, limited members of the parent left out */
    private function groups(?GroupAccess $access = null): Collection
    {
        $access ??= GroupAccess::for($this->user());
        [$from] = $this->period();

        return $access->corporateGroups()
            ->filter(fn (CorporateGroup $group): bool => ConsolidateGroup::level($access, $group, $from)->sees())
            ->values();
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} the period when both dates are valid, else nulls */
    private function period(): array
    {
        try {
            [$from, $to] = ConsolidateGroup::period(['from' => $this->from, 'to' => $this->to]);
        } catch (ValidationException) {
            return [null, null];
        }

        return [$from, $to];
    }

    /** The parent's fiscal year that contains today, else its newest one. */
    private function defaultPeriod(): void
    {
        $group = $this->group === '' ? null : CorporateGroup::query()->find((int) $this->group);

        if (! $group instanceof CorporateGroup) {
            $this->from = '';
            $this->to = '';

            return;
        }

        $today = CarbonImmutable::today()->toDateString();
        $year = CurrentCompany::run($group->parent_company_id, fn (): ?FiscalYear => FiscalYear::query()
            ->orderByRaw('(start_date <= ? AND end_date >= ?) DESC', [$today, $today])
            ->orderByDesc('start_date')
            ->first());

        $this->from = $year?->start_date->toDateString() ?? '';
        $this->to = $year?->end_date->toDateString() ?? '';
    }

    private function resetManual(): void
    {
        $this->manualType = EliminationType::Other->value;
        $this->manualReason = '';
        $this->manualRows = [
            ['company' => '', 'account' => '', 'debit' => '', 'credit' => ''],
            ['company' => '', 'account' => '', 'debit' => '', 'credit' => ''],
        ];
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('Du har inte behörighet att göra det.'), 'error');
        } catch (ModelNotFoundException $exception) {
            // A group the user does not see answers 404, exactly like one that does not exist.
            abort_if($exception->getModel() === CorporateGroup::class, 404);
            $this->toast(__('Posten finns inte i den här koncernen.'), 'error');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
