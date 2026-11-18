<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConvertToCorporateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\RemoveGroupMember;
use App\Actions\Group\RevokeGroupRole;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\CorporateGroupOverview;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Koncern → Översikt (ADR-081 E22, ADR-060 S2-1.2): the structure of a shared
 * group, its members' holdings and period status, and the group's
 * administration (create, convert a list, add and remove members, record
 * holdings, grant the viewer role). Spans companies, so what is shown is
 * decided by GroupAccess on every render and every action, never from the
 * state the browser sends back. Every write goes through an Action that
 * authorizes it again.
 */
class CorporateGroupPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    #[Url(as: 'koncern', except: '')]
    public string $group = '';

    public string $newName = '';

    public string $newParent = '';

    public string $convertList = '';

    public string $convertParent = '';

    public string $addCompany = '';

    public string $holdHolder = '';

    public string $holdHeld = '';

    public string $holdCapital = '';

    public string $holdVoting = '';

    public string $holdBasis = '';

    public string $holdAcquired = '';

    public string $holdDisposed = '';

    public string $roleUser = '';

    public function mount(): void
    {
        if ($this->group === '') {
            return;
        }

        // A group the user does not see answers 404, without its name.
        GroupAccess::for($this->user())->corporateGroup((int) $this->group);
    }

    public function select(string $group): void
    {
        $this->group = (string) GroupAccess::for($this->user())->corporateGroup((int) $group)->id;
    }

    public function create(CreateCorporateGroup $create): void
    {
        $this->attempt(function () use ($create): void {
            $parent = $this->company((int) $this->newParent);
            $group = $create($this->user(), $parent, $this->newName);
            $this->group = (string) $group->id;
            $this->reset('newName', 'newParent');
            $this->toast(__('Koncernen är skapad. Registrera innehaven i dotterföretagen och lägg till dem.'));
        });
    }

    public function convert(ConvertToCorporateGroup $convert): void
    {
        $this->attempt(function () use ($convert): void {
            $result = $convert($this->user(), (int) $this->convertList, (int) $this->convertParent);
            $this->group = (string) $result['group']->id;
            $this->reset('convertList', 'convertParent');
            $this->toast($result['pending'] === 0
                ? __('Koncernen är skapad.')
                : trans_choice('{1} Koncernen är skapad. Ett företag i din lista återstår att lägga till: registrera innehavet och lägg till det.|[2,*] Koncernen är skapad. :count företag i din lista återstår att lägga till: registrera innehaven och lägg till dem.', $result['pending'], ['count' => $result['pending']]));
        });
    }

    public function addMember(ConfirmGroupMember $confirm): void
    {
        $this->attempt(function () use ($confirm): void {
            $confirm($this->user(), (int) $this->group, $this->company((int) $this->addCompany));
            $this->reset('addCompany');
            $this->toast(__('Företaget ingår nu i koncernen.'));
        });
    }

    public function removeMember(int $companyId, RemoveGroupMember $remove): void
    {
        $this->attempt(function () use ($remove, $companyId): void {
            $remove($this->user(), (int) $this->group, $companyId);
            $this->toast(__('Företaget ingår inte längre i koncernen.'));
        });
    }

    public function saveHolding(RecordHolding $record): void
    {
        $this->attempt(function () use ($record): void {
            // Both ends are companies the user belongs to; RecordHolding checks company:update in the holder.
            $holder = $this->company((int) $this->holdHolder);
            $held = $this->company((int) $this->holdHeld);
            $orgNumber = OrganisationNumber::tryFrom((string) $held->org_number)?->formatted();

            $existing = $orgNumber === null ? null : CurrentCompany::run($holder, fn (): ?int => CompanyHolding::query()
                ->where('held_org_number', $orgNumber)->whereNull('disposed_on')->value('id'));

            $record($this->user(), $holder, [
                'held_org_number' => (string) $held->org_number,
                'held_name' => $held->name,
                'capital' => $this->holdCapital,
                'voting' => $this->holdVoting,
                'control_basis' => $this->holdBasis,
                'acquired_on' => $this->holdAcquired,
                'disposed_on' => $this->holdDisposed === '' ? null : $this->holdDisposed,
            ], $existing === null ? null : (int) $existing);

            $this->reset('holdHeld', 'holdCapital', 'holdVoting', 'holdBasis', 'holdAcquired', 'holdDisposed');
            $this->toast(__('Innehavet är sparat.'));
        });
    }

    public function grantRole(GrantGroupRole $grant): void
    {
        $this->attempt(function () use ($grant): void {
            $grant($this->user(), (int) $this->group, (int) $this->roleUser, 'viewer');
            $this->reset('roleUser');
            $this->toast(__('Personen kan nu läsa koncernens sammanställning.'));
        });
    }

    public function revokeRole(int $userId, RevokeGroupRole $revoke): void
    {
        $this->attempt(function () use ($revoke, $userId): void {
            $revoke($this->user(), (int) $this->group, $userId, 'viewer');
            $this->toast(__('Rollen är borttagen.'));
        });
    }

    public function render(CorporateGroupOverview $overview): View
    {
        $user = $this->user();
        $access = GroupAccess::for($user);
        $groups = $access->corporateGroups();
        $current = $this->group === '' ? $groups->first() : $groups->firstWhere('id', (int) $this->group);

        if ($this->group !== '' && $current === null) {
            // No longer visible: left, archived or removed elsewhere. It must look the same as one that never existed.
            $current = $groups->first();
            $this->toast(__('Koncernen finns inte längre, eller så har du inte längre tillgång till den.'), 'error');
        }

        $this->group = $current instanceof CorporateGroup ? (string) $current->id : '';
        $report = $current instanceof CorporateGroup ? $overview->build($access, $current) : null;
        $inGroup = $report === null ? [] : array_column($report['nodes'], 'company_id');
        $companies = $access->companies();
        $administrable = $companies->filter(fn (Company $company): bool => $access->mayAdminister($company->id))->values();
        $parents = CorporateGroup::query()->whereIn('parent_company_id', $administrable->pluck('id'))->pluck('parent_company_id')->all();

        return view('livewire.group.corporate-group-page', [
            'groups' => $groups,
            'current' => $current,
            'report' => $report,
            'full' => $report !== null && $report['level'] === GroupAccessLevel::Full,
            'canCreate' => $administrable->reject(fn (Company $company): bool => in_array($company->id, $parents, true))->values(),
            'addable' => $administrable->reject(fn (Company $company): bool => in_array($company->id, $inGroup, true))->values(),
            'holders' => $report === null || ! $report['administers'] ? collect() : $companies->filter(fn (Company $company): bool => in_array($company->id, $inGroup, true) && $access->mayUpdate($company->id))->values(),
            'held' => $administrable,
            'lists' => $access->groups()->load('members'),
            'bases' => ControlBasis::cases(),
            'companyNames' => $companies->pluck('name', 'id'),
        ]);
    }

    private function company(int $id): Company
    {
        // Only a company the user belongs to; anything else answers 404 like a company that does not exist.
        $company = GroupAccess::for($this->user())->companies()->firstWhere('id', $id);

        if (! $company instanceof Company) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$id]);
        }

        // The permission check in RecordHolding reads the members of the company.
        return $company->loadMissing('users');
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage() !== '' ? $exception->getMessage() : __('Du har inte behörighet till det här.'), 'error');
        } catch (ModelNotFoundException) {
            $this->toast(__('Koncernen eller företaget finns inte, eller så har du inte tillgång till det.'), 'error');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
