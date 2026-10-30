<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\TeamInvitation;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Rules\AvailableRole;
use App\Support\Audit\Audit;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;

/**
 * Jetstream's member manager with a toast after every action, and with the
 * permission check that Jetstream's cancelTeamInvitation() lacks.
 */
class MemberManager extends TeamMemberManager
{
    use ShowsToasts;

    public function addTeamMember(): void
    {
        $email = (string) ($this->addTeamMemberForm['email'] ?? '');

        parent::addTeamMember();

        $this->toast(__('Inbjudan är skickad till :email.', ['email' => $email]));
    }

    /**
     * @param  int  $invitationId
     */
    public function cancelTeamInvitation($invitationId): void
    {
        abort_unless(Gate::check('removeTeamMember', $this->team), 403);

        $invitation = TeamInvitation::query()
            ->whereKey($invitationId)
            ->where('team_id', $this->team->id)
            ->first();

        parent::cancelTeamInvitation($invitationId);

        if ($invitation instanceof TeamInvitation) {
            Audit::record('company.invitation_cancelled', null, ['email' => $invitation->email], $this->team->id);

            $this->toast(__('Inbjudan till :email är återkallad.', ['email' => $invitation->email]));
        }
    }

    /**
     * Jetstream's roles, without those of modules that are off for the
     * company (PAYROLL_MODULE.md A.7).
     *
     * @return list<Role>
     */
    public function getRolesProperty()
    {
        return array_values(array_filter(parent::getRolesProperty(), function (Role $role): bool {
            $module = app(ModuleRegistry::class)->moduleForRole($role->key);

            return $module === null || app(ModuleManager::class)->isEnabled($module->key(), $this->team);
        }));
    }

    public function updateRole(UpdateTeamMemberRole $updater): void
    {
        Validator::make(['role' => $this->currentRole], ['role' => [new AvailableRole($this->team)]])->validate();

        $name = $this->managingRoleFor->name;
        $role = Jetstream::findRole($this->currentRole);

        parent::updateRole($updater);

        $this->toast(__(':name är nu :role.', ['name' => $name, 'role' => mb_strtolower((string) $role?->name)]));
    }

    public function removeTeamMember(RemovesTeamMembers $remover): void
    {
        $name = Jetstream::findUserByIdOrFail((int) $this->teamMemberIdBeingRemoved)->name;

        parent::removeTeamMember($remover);

        $this->toast(__(':name har inte längre tillgång till företaget.', ['name' => $name]));
    }

    public function leaveTeam(RemovesTeamMembers $remover)
    {
        $company = $this->team->name;

        $response = parent::leaveTeam($remover);

        Toast::flash(__('Du har lämnat :company.', ['company' => $company]));

        return $response;
    }
}
