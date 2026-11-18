<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\ConsolidateGroup;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupReport;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRun;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Koncern → Koncernrapporter (ADR-060, addendum 2026-11-07, 3.2 S2-1.6;
 * ADR-081 E26): the consolidated income statement and balance sheet of a
 * stored run, with the CSV and PDF files. Read only: runs are made under
 * Konsolidering, eliminations under Elimineringar. Spans companies, so what
 * is shown is decided by GroupAccess and GroupReport on every render, never
 * from the state the browser sends back.
 */
class GroupReportsPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    /** How many runs the run picker lists. */
    public const int RUNS = 20;

    #[Url(as: 'koncern', except: '')]
    public string $group = '';

    #[Url(as: 'korning', except: '')]
    public string $run = '';

    public function mount(): void
    {
        $groups = $this->groups();

        if ($this->group !== '' && $groups->firstWhere('id', (int) $this->group) === null) {
            // A group the user does not see answers 404, exactly like one that does not exist.
            abort(404);
        }

        $this->group = $this->group !== '' ? $this->group : (string) ($groups->first()->id ?? '');
    }

    public function selectGroup(string $group): void
    {
        if ($this->groups()->firstWhere('id', (int) $group) === null) {
            abort(404);
        }

        $this->group = $group;
        $this->reset('run');
    }

    public function selectRun(string $run): void
    {
        $this->run = $run;
    }

    public function render(): View
    {
        $access = GroupAccess::for($this->user());
        $groups = $this->groups($access);
        $current = $groups->firstWhere('id', (int) $this->group);

        if ($this->group !== '' && $current === null) {
            // Access ended while the page was open: shown as if the group did not exist.
            $this->reset('group', 'run');
            $this->toast(__('Koncernen visas inte längre. Du kan ha förlorat behörigheten i ett av företagen.'), 'error');
        }

        $runs = new Collection;
        $report = null;
        $withheld = false;
        $selected = null;

        if ($current instanceof CorporateGroup) {
            $runs = CorporateGroupRun::query()
                ->where('corporate_group_id', $current->id)
                ->orderByDesc('id')
                ->limit(self::RUNS)
                ->get(['id', 'corporate_group_id', 'period_from', 'period_to', 'preliminary', 'created_at']);

            // Only this group's runs can be picked; any other id falls back to the newest run of the group.
            $selected = $runs->firstWhere('id', (int) $this->run) ?? $runs->first();
            $selected = $selected === null ? null : CorporateGroupRun::query()->with('runner:id,name')->whereKey($selected->id)->first();

            if ($selected instanceof CorporateGroupRun) {
                // Decided by the companies the run read (cold review E26, P1); a run the user may not see is withheld.
                $withheld = ! ConsolidateGroup::runLevel($access, $current, $selected)->sees();
                $report = $withheld ? null : GroupReport::build($access, $selected);
            }
        }

        return view('livewire.group.group-reports-page', [
            'groups' => $groups,
            'current' => $current,
            'runs' => $runs,
            'selected' => $selected,
            'report' => $report,
            'withheld' => $withheld,
        ]);
    }

    /** @return Collection<int, CorporateGroup> the groups the user sees, limited members of the parent left out */
    private function groups(?GroupAccess $access = null): Collection
    {
        $access ??= GroupAccess::for($this->user());

        return $access->corporateGroups()
            ->filter(fn (CorporateGroup $group): bool => ConsolidateGroup::level($access, $group)->sees())
            ->values();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
