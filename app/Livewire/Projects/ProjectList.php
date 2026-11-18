<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Projects\ProjectResults;
use App\Domain\Projects\ProjectStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every project's result over its whole life (ADR-081 E7): revenue, costs,
 * contribution and margin, and the budgeted contribution. A member limited
 * to cost centres or projects sees only their own projects, without payroll
 * (gap 10). Five queries, whatever the number of projects, years or rows.
 */
class ProjectList extends Component
{
    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewResults', [Project::class, $this->company()]);
    }

    public function render(): View
    {
        $company = $this->company();
        $scope = DimensionScope::for($this->user(), $company);
        $status = ProjectStatus::tryFrom($this->status);
        $canSeeBudget = $this->user()->hasTeamPermission($company, 'budgets:view');

        $projects = Project::query()
            ->with('customer:id,name')
            ->when($scope->restricted, fn ($query) => $query->whereIn('id', $scope->projectIds))
            ->when($status, fn ($query, ProjectStatus $chosen) => $query->where('status', $chosen->value))
            ->orderBy('code')
            ->get();

        $years = FiscalYear::query()->orderBy('start_date')->get();
        $results = $projects->isEmpty() ? [] : ProjectResults::perProject($years, $scope->restricted ? $scope->projectIds : null, $scope->restricted, $canSeeBudget);

        return view('livewire.projects.project-list', [
            'projects' => $projects,
            'results' => $results,
            'statuses' => ProjectStatus::cases(),
            'canSeeBudget' => $canSeeBudget,
            'limited' => $scope->restricted,
        ]);
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
