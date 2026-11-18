<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Projects\ProjectResults;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One project's P&L (ADR-081 gap 6 scope 3, E7): revenue, costs by group,
 * contribution and margin for the project's whole life or one fiscal year
 * (then also per month), against the active budget's lines on the project
 * and against the contract sum and estimated cost, with drill-down from an
 * account to its posted rows. The choices are kept in the URL (AGENTS.md
 * §11.3).
 *
 * A member limited to cost centres or projects reaches only their own
 * projects (ProjectPolicy::viewResult) and never sees payroll vouchers
 * (ADR-081 gap 10); in the drill-down a voucher they may not open shows only
 * the row's own text, without a link, as in the Huvudbok.
 *
 * A fixed number of queries, whatever the number of years, accounts or rows.
 */
class ProjectResult extends Component
{
    #[Locked]
    public int $projectId;

    /** A fiscal year id; empty for the project's whole life. */
    #[Url(except: '')]
    public string $ar = '';

    /** The account whose rows are shown. */
    #[Url(except: '')]
    public string $konto = '';

    public function mount(Project $project): void
    {
        Gate::authorize('viewResult', $project);

        $this->projectId = $project->id;
    }

    public function drill(int $account): void
    {
        $this->konto = $this->konto === (string) $account ? '' : (string) $account;
    }

    public function closeDrill(): void
    {
        $this->konto = '';
    }

    public function render(): View
    {
        $project = Project::query()->with(['customer:id,name', 'manager:id,name', 'defaultCostCenter:id,code,name'])->findOrFail($this->projectId);
        Gate::authorize('viewResult', $project);

        $company = $this->company();
        $scope = DimensionScope::for($this->user(), $company);
        $canSeeBudget = $this->user()->hasTeamPermission($company, 'budgets:view');

        $years = FiscalYear::query()->orderBy('start_date')->get();
        $year = $years->firstWhere('id', (int) $this->ar);
        $this->ar = $year instanceof FiscalYear ? (string) $year->id : '';
        $range = $year instanceof FiscalYear ? collect([$year]) : $years;

        $report = ProjectResults::forProject($project, $range, $scope->restricted, $canSeeBudget);
        $account = preg_match('/^\d{4}$/', $this->konto) === 1 ? (int) $this->konto : null;
        $drill = $account === null ? null : $this->drillDown($project, $range, $account, $scope, $company);
        $this->konto = $account === null ? '' : (string) $account;

        return view('livewire.projects.project-result', [
            'project' => $project,
            // A project manager is shown only while still a member of the company (ADR-081 gap 6).
            'manager' => $this->currentManager($project, $company),
            'years' => $years->sortByDesc('start_date')->values(),
            'year' => $year,
            'report' => $report,
            'estimate' => $year === null ? ProjectResults::estimate($project, $report['actual']['revenue'], $report['actual']['revenue'] - $report['actual']['result']) : null,
            'drill' => $drill,
            'account' => $account,
            'canSeeBudget' => $canSeeBudget,
            'limited' => $scope->restricted,
            // The existing account analysis (Huvudbok for one account on the project) of the chosen year.
            'ledgerLink' => $year instanceof FiscalYear && $this->user()->hasTeamPermission($company, 'reports:view')
                ? fn (int $number): string => route('accounting.reports', ['rapport' => 'huvudbok', 'ar' => (string) $year->id, 'projekt' => $project->code, 'konto' => (string) $number])
                : null,
        ]);
    }

    /**
     * The rows of one account on the project, each marked whether its voucher
     * may be opened: by everyone with vouchers:view, for a limited member
     * only when the voucher is inside their scope (one query for all rows).
     *
     * @param  Collection<int, FiscalYear>  $range
     * @return array{rows: list<array{row_id: int, voucher_id: string, date: string, reference: string, text: string, amount: int, linked: bool}>, more: bool}
     */
    private function drillDown(Project $project, Collection $range, int $account, DimensionScope $scope, Company $company): array
    {
        $found = ProjectResults::rows($project, $range, $account, $scope->restricted);
        $mayOpen = [];

        if ($found['rows'] !== [] && $this->user()->hasTeamPermission($company, 'vouchers:view')) {
            $ids = array_values(array_unique(array_column($found['rows'], 'voucher_id')));
            $mayOpen = $scope->restricted
                ? array_flip($scope->constrainVouchers(Voucher::query()->whereIn('vouchers.id', $ids))->pluck('vouchers.id')->map(fn (mixed $id): string => (string) $id)->all())
                : array_flip($ids);
        }

        $rows = array_map(function (array $row) use ($mayOpen): array {
            $linked = isset($mayOpen[$row['voucher_id']]);

            return [
                'row_id' => $row['row_id'],
                'voucher_id' => $row['voucher_id'],
                'date' => $row['date'],
                'reference' => $row['reference'],
                // Only the row's own text for a voucher the member may not open, as in the Huvudbok (ADR-081 gap 10).
                'text' => $linked && $row['row_text'] === '' ? $row['voucher_text'] : $row['row_text'],
                'amount' => $row['amount'],
                'linked' => $linked,
            ];
        }, $found['rows']);

        return ['rows' => $rows, 'more' => $found['more']];
    }

    private function currentManager(Project $project, Company $company): ?User
    {
        $manager = $project->manager;

        if (! $manager instanceof User) {
            return null;
        }

        return (int) $company->user_id === $manager->id || $company->users()->whereKey($manager->id)->exists() ? $manager : null;
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
