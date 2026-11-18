<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\MapGroupAccount;
use App\Domain\Group\ConsolidationView;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRun;
use App\Models\FiscalYear;
use App\Models\GroupAccountMap;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SpansCompanies;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Koncern → Konsolidering (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E24):
 * run the consolidation of a shared group for a period, read a stored run,
 * check it against the books, and keep the group's account map. Spans
 * companies, so what is shown is decided by GroupAccess on every render and
 * every action, never from the state the browser sends back. Thin: every
 * write goes through an Action.
 */
class ConsolidationPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    /** How many runs the log lists. */
    public const int RUNS = 20;

    #[Url(as: 'koncern', except: '')]
    public string $group = '';

    #[Url(as: 'korning', except: '')]
    public string $run = '';

    public string $from = '';

    public string $to = '';

    public string $mapCompany = '';

    public string $mapAccount = '';

    public string $mapGroupAccount = '';

    public function mount(): void
    {
        $groups = $this->groups();

        if ($this->group !== '' && $groups->firstWhere('id', (int) $this->group) === null) {
            // A group the user does not see answers 404, exactly like one that does not exist.
            abort(404);
        }

        $this->group = $this->group !== '' ? $this->group : (string) ($groups->first()->id ?? '');
        $this->defaultPeriod();
    }

    public function selectGroup(string $group): void
    {
        if ($this->groups()->firstWhere('id', (int) $group) === null) {
            abort(404);
        }

        $this->group = $group;
        $this->reset('run', 'mapCompany', 'mapAccount', 'mapGroupAccount');
        $this->defaultPeriod();
    }

    public function selectRun(string $run): void
    {
        $this->run = $run;
    }

    public function consolidate(ConsolidateGroup $consolidate): void
    {
        $this->attempt(function () use ($consolidate): void {
            $run = $consolidate($this->user(), (int) $this->group, ['from' => $this->from, 'to' => $this->to]);
            $this->run = (string) $run->id;
            $this->toast($run->preliminary
                ? __('Konsolideringen är klar och sparad i körloggen. Den är preliminär eftersom någon period inte är låst.')
                : __('Konsolideringen är klar och sparad i körloggen.'));
        });
    }

    public function check(int $runId, ConsolidateGroup $consolidate): void
    {
        $this->attempt(function () use ($consolidate, $runId): void {
            $result = $consolidate->check($this->user(), (int) $this->group, $runId);

            match (true) {
                ! $result['stored_intact'] => $this->toast(__('Körningen stämmer inte med sina kontrollsummor. Kontakta den som driver NONOX.'), 'error'),
                ! $result['inputs_unchanged'] => $this->toast(__('Bokföringen, markeringarna eller kontomappningen har ändrats sedan körningen. Kör en ny konsolidering för aktuella siffror.'), 'error'),
                $result['output_reproduced'] !== true => $this->toast(__('Samma indata gav inte samma resultat. Kontakta den som driver NONOX.'), 'error'),
                default => $this->toast(__('Indata är oförändrade och ger samma siffror som när körningen gjordes.')),
            };
        });
    }

    public function saveMap(MapGroupAccount $map): void
    {
        $this->attempt(function () use ($map): void {
            $map->save($this->user(), (int) $this->group, (int) $this->mapCompany, ['account' => $this->mapAccount, 'group_account' => $this->mapGroupAccount]);
            $this->reset('mapAccount', 'mapGroupAccount');
            $this->toast(__('Kontomappningen är sparad. Den gäller från nästa konsolidering.'));
        });
    }

    public function removeMap(int $mapId, MapGroupAccount $map): void
    {
        $this->attempt(function () use ($map, $mapId): void {
            $map->remove($this->user(), (int) $this->group, $mapId);
            $this->toast(__('Kontomappningen är borttagen. Kontot följer sitt eget nummer från nästa konsolidering.'));
        });
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
        $selected = null;
        $view = null;
        $withheld = false;
        $maps = collect();
        $members = collect();
        $accounts = [];
        $administers = false;
        $level = GroupAccessLevel::None;

        if ($current instanceof CorporateGroup) {
            $level = ConsolidateGroup::level($access, $current, $this->periodStart());
            $runs = CorporateGroupRun::query()
                ->where('corporate_group_id', $current->id)
                ->with('runner:id,name')
                ->orderByDesc('id')
                ->limit(self::RUNS)
                ->get(['id', 'corporate_group_id', 'period_from', 'period_to', 'run_by', 'preliminary', 'created_at', 'inputs_hash', 'output_hash']);

            $selected = $this->run === ''
                ? $runs->first()
                : $runs->firstWhere('id', (int) $this->run);

            $selected = $selected === null ? null : CorporateGroupRun::query()->whereKey($selected->id)->first();
            $runLevel = $selected === null ? GroupAccessLevel::None : ConsolidateGroup::level($access, $current, $selected->period_from);
            // A run with one contributing company or a company left out is never shown to someone who sees only sums.
            $withheld = $selected !== null && $runLevel === GroupAccessLevel::Consolidated && ! ConsolidateGroup::shownToConsolidated($selected->inputs);
            $view = $runLevel->sees() && $selected !== null && ! $withheld ? ConsolidationView::present($selected, $runLevel) : null;

            if ($level === GroupAccessLevel::Full) {
                $administers = $access->administers($current);
                $ids = $access->includedCompanyIds($current);
                $members = Company::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
                // Across the members on purpose: FULL access means reports:view in every one of these companies,
                // and only this group's rows are read.
                $maps = CompanyScope::bypass(fn () => GroupAccountMap::query()->where('corporate_group_id', $current->id)
                    ->whereIn('company_id', $ids)->orderBy('company_id')->orderBy('account_number')->get());

                if ($administers && $this->mapCompany !== '' && in_array((int) $this->mapCompany, $ids, true)) {
                    $accounts = CurrentCompany::run((int) $this->mapCompany, fn (): array => Account::query()->orderBy('number')->get(['number', 'name'])->all());
                }
            }
        }

        return view('livewire.group.consolidation-page', [
            'groups' => $groups,
            'current' => $current,
            'level' => $level,
            'runs' => $runs,
            'selected' => $selected,
            'view' => $view,
            'withheld' => $withheld,
            'members' => $members,
            'names' => $members->pluck('name', 'id'),
            'maps' => $maps,
            'accounts' => $accounts,
            'administers' => $administers,
        ]);
    }

    /** @return Collection<int, CorporateGroup> the groups the user sees, limited members of the parent left out */
    private function groups(?GroupAccess $access = null): Collection
    {
        $access ??= GroupAccess::for($this->user());

        return $access->corporateGroups()
            ->filter(fn (CorporateGroup $group): bool => ConsolidateGroup::level($access, $group, $this->periodStart())->sees())
            ->values();
    }

    private function periodStart(): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $this->from)?->startOfDay() : null;
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

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('Du har inte behörighet att göra det.'), 'error');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
