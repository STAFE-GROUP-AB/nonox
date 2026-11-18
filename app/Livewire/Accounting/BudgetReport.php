<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Budget\BudgetComparison;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Actual against budget (ADR-081 gap 2 scope 4): the income statement for a
 * month and for the year to date, against a budget version, optionally for
 * one cost centre or project, with each account linked to its account
 * analysis. The choices are kept in the URL (AGENTS.md §11.3). Seven
 * queries, whatever the size of the ledger or the budget.
 */
class BudgetReport extends Component
{
    #[Url(except: '')]
    public string $budget = '';

    #[Url(except: '')]
    public string $manad = '';

    #[Url(except: '')]
    public string $kst = '';

    #[Url(except: '')]
    public string $projekt = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [BudgetVersion::class, $this->company()]), 403);
    }

    public function updatedBudget(): void
    {
        $this->reset('manad');
    }

    public function render(): View
    {
        $versions = BudgetVersion::query()->with('fiscalYear')->where('kind', BudgetKind::Budget->value)->get()
            ->sortByDesc(fn (BudgetVersion $version): string => $version->fiscalYear->start_date->toDateString().($version->is_active ? '1' : '0'))
            ->values();

        $version = $versions->firstWhere('id', (int) $this->budget)
            ?? $versions->first(fn (BudgetVersion $version): bool => $version->is_active && $version->fiscalYear->contains(now()->toImmutable()->startOfDay()))
            ?? $versions->firstWhere('is_active', true)
            ?? $versions->first();

        $costCenters = CostCenter::query()->orderBy('code')->get(['id', 'code', 'name']);
        $projects = Project::query()->orderBy('code')->get(['id', 'code', 'name']);

        if (! $version instanceof BudgetVersion) {
            return view('livewire.accounting.budget-report', ['version' => null, 'versions' => $versions, 'costCenters' => $costCenters, 'projects' => $projects, 'report' => null, 'ledgerLink' => null]);
        }

        $costCenter = $costCenters->firstWhere('code', $this->kst);
        $project = $projects->firstWhere('code', $this->projekt);
        // A month may come as a date (a bookmark, a link with a day); only its month counts.
        $month = substr($this->manad, 0, 7);
        $report = BudgetComparison::build($version, $month !== '' ? $month : $this->defaultMonth($version), $costCenter?->id, $project?->id);

        // The selects show what the report shows, also when the URL chose nothing.
        $this->budget = (string) $version->id;
        $this->manad = $report['month'];

        $canSeeLedger = $this->user()->hasTeamPermission($this->company(), 'reports:view');
        $start = $version->fiscalYear->start_date->toDateString();
        $through = CarbonImmutable::createFromFormat('!Y-m', $report['month'])?->endOfMonth()->min($version->fiscalYear->end_date)->toDateString();

        return view('livewire.accounting.budget-report', [
            'version' => $version,
            'versions' => $versions,
            'costCenters' => $costCenters,
            'projects' => $projects,
            'report' => $report,
            // The existing account analysis (Huvudbok for one account) of the same year to date, cost centre and project.
            'ledgerLink' => $canSeeLedger ? fn (int $account): string => route('accounting.reports', array_filter([
                'rapport' => 'huvudbok',
                'ar' => (string) $version->fiscal_year_id,
                'fran' => $start,
                'till' => (string) $through,
                'kst' => (string) $costCenter?->code,
                'projekt' => (string) $project?->code,
                'konto' => (string) $account,
            ], fn (string $value): bool => $value !== '')) : null,
        ]);
    }

    /** The current month when it is in the budget's year, else the year's last month. */
    private function defaultMonth(BudgetVersion $version): string
    {
        $months = BudgetMonths::of($version->fiscalYear);
        $now = now()->format('Y-m');

        return in_array($now, $months, true) ? $now : ($months[count($months) - 1] ?? $now);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
