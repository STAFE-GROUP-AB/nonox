<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\SetDimensionScope;
use App\Domain\Company\CompanyRole;
use App\Domain\Company\ScopeDimension;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\MembershipDimensionScope;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Behörighet per enhet" on the company's settings (ADR-081 gap 10): the owner
 * or an admin limits a member to cost centres and projects. Only members whose
 * role can be limited are listed; owner, admin, accountant and auditor never
 * are. A fixed number of queries, whatever the number of people.
 */
class DimensionScopes extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    /** The member whose scope is being edited. */
    #[Locked]
    public ?int $editing = null;

    /** @var list<int|string> */
    public array $costCenterIds = [];

    /** Read once per request. */
    private ?Company $company = null;

    /** @var list<int|string> */
    public array $projectIds = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'members:scope'), 403);

        $this->companyId = $company->id;
    }

    public function edit(int $userId): void
    {
        $member = $this->members()->firstWhere('user_id', $userId);
        abort_if($member === null, 404);

        $this->editing = $userId;
        $this->costCenterIds = $member['cost_center_ids'];
        $this->projectIds = $member['project_ids'];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editing', 'costCenterIds', 'projectIds');
        $this->resetErrorBag();
    }

    public function save(SetDimensionScope $set): void
    {
        if ($this->editing === null) {
            return;
        }

        $costCenters = array_map('intval', $this->costCenterIds);
        $projects = array_map('intval', $this->projectIds);

        try {
            $set($this->user(), $this->company(), $this->editing, $costCenters, $projects);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->toast($costCenters === [] && $projects === []
            ? __('Begränsningen är borttagen.')
            : __('Behörigheten är sparad. Personen når nu bara de valda kostnadsställena och projekten.'));
        $this->cancel();
    }

    public function lift(int $userId, SetDimensionScope $set): void
    {
        try {
            $set($this->user(), $this->company(), $userId, [], []);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->toast(__('Begränsningen är borttagen.'));
        $this->cancel();
    }

    public function render(): View
    {
        $company = $this->company();

        return CurrentCompany::run($company, fn (): View => view('livewire.company.dimension-scopes', [
            'members' => $this->members(),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['id', 'code', 'name']),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]));
    }

    /**
     * The members whose role can be limited, with their current scope: two
     * queries in all.
     *
     * @return Collection<int, array{user_id: int, name: string, email: string, role: string, role_label: string, limited: bool, module_role: bool, cost_center_ids: list<int>, project_ids: list<int>}>
     */
    private function members(): Collection
    {
        $company = $this->company();

        $rows = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $company->id)
            ->where('team_user.user_id', '<>', $company->user_id)
            ->whereNotIn('team_user.role', DimensionScope::UNSCOPABLE_ROLES)
            ->orderBy('users.name')
            ->get(['team_user.id AS membership_id', 'team_user.user_id', 'team_user.role', 'team_user.dimension_scoped', 'users.name', 'users.email']);

        // This company's rows, for the memberships listed above (the page may be another company's than the current one).
        $scopes = CurrentCompany::run($company, fn () => MembershipDimensionScope::query()
            ->whereIn('team_user_id', $rows->pluck('membership_id')->all())
            ->get(['team_user_id', 'dimension', 'object_id'])
            ->groupBy('team_user_id'));

        return $rows->map(function (object $row) use ($scopes): array {
            /** @var Collection<int, MembershipDimensionScope> $own */
            $own = $scopes->get((int) $row->membership_id, collect());
            $ids = fn (ScopeDimension $dimension): array => array_values(array_map('intval', $own->where('dimension', $dimension)->pluck('object_id')->all()));
            $role = (string) $row->role;

            return [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => $role,
                'role_label' => isset(Jetstream::$roles[$role]) ? (string) Jetstream::$roles[$role]->name : $role,
                'limited' => DimensionScope::limitsMembership($role, (bool) $row->dimension_scoped),
                // A role of a module: no module page applies a scope yet, so a limited one reaches nothing there.
                'module_role' => CompanyRole::tryFrom($role) === null,
                'cost_center_ids' => $ids(ScopeDimension::CostCenter),
                'project_ids' => $ids(ScopeDimension::Project),
            ];
        })->values();
    }

    private function company(): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }

        $company = Company::query()->findOrFail($this->companyId);

        abort_unless($this->user()->hasTeamPermission($company, 'members:scope'), 403);

        return $this->company = $company;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
