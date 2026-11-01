<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Auth\Passkeys\ResetPasskeys;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Rules\AvailableRole;
use App\Support\Audit\Audit;
use App\Support\Auth\PasskeySession;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;
use Laravel\Passkeys\Passkey;

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
        $memberId = (int) $this->managingRoleFor->id;
        $pending = $this->invitationsSentBy($memberId);

        parent::updateRole($updater);

        $withdrawn = $pending - $this->invitationsSentBy($memberId);

        $this->toast(trim(__(':name är nu :role.', ['name' => $name, 'role' => mb_strtolower((string) $role?->name)]).' '.$this->withdrawnMessage($name, $withdrawn)));
    }

    public function removeTeamMember(RemovesTeamMembers $remover): void
    {
        $memberId = (int) $this->teamMemberIdBeingRemoved;
        $name = Jetstream::findUserByIdOrFail($memberId)->name;
        $pending = $this->invitationsSentBy($memberId);

        parent::removeTeamMember($remover);

        $withdrawn = $pending - $this->invitationsSentBy($memberId);

        $this->toast(trim(__(':name har inte längre tillgång till företaget.', ['name' => $name]).' '.$this->withdrawnMessage($name, $withdrawn)));
    }

    /**
     * Members whose passkeys the signed-in user may reset here (ADR-029),
     * looked up in one query for the whole list.
     *
     * @return list<int>
     */
    public function getPasskeyResettableProperty(): array
    {
        $by = Auth::user();
        $company = $this->team;

        if (! $by instanceof User || ! $company instanceof Company) {
            return [];
        }

        $withPasskeys = Passkey::query()->whereIn('user_id', $company->users->modelKeys())->distinct()->pluck('user_id')->all();
        $confirmed = PasskeySession::isVerified() || PasskeySession::skippedLocally();
        $resettable = [];

        foreach ($company->users as $member) {
            if ($member instanceof User && in_array($member->id, $withPasskeys, false) && ResetPasskeys::allowed($by, $company, $member, $confirmed)) {
                $resettable[] = $member->id;
            }
        }

        return $resettable;
    }

    public function resetPasskeys(int $userId, ResetPasskeys $reset): void
    {
        $by = Auth::user();
        $company = $this->team;
        $member = $company instanceof Company ? $company->users->firstWhere('id', $userId) : null;

        abort_unless($member instanceof User && $by instanceof User, 404);

        $removed = $reset($by, $company, $member, PasskeySession::isVerified() || PasskeySession::skippedLocally());

        $this->toast(__(':name har fått sina passkeys återställda (:count borttagna) och har loggats ut. Hen lägger till en ny passkey vid nästa inloggning och har fått ett mejl om det.', ['name' => $member->name, 'count' => $removed]));
    }

    private function invitationsSentBy(int $userId): int
    {
        return TeamInvitation::query()->where('team_id', $this->team->id)->where('invited_by', $userId)->count();
    }

    /** The toast's mention of invitations withdrawn with their sender (WithdrawInvitationsSentBy). */
    private function withdrawnMessage(string $name, int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count === 1 => __('En obesvarad inbjudan som :name skickat är återkallad.', ['name' => $name]),
            default => __(':count obesvarade inbjudningar som :name skickat är återkallade.', ['name' => $name, 'count' => $count]),
        };
    }

    public function leaveTeam(RemovesTeamMembers $remover)
    {
        $company = $this->team->name;

        $response = parent::leaveTeam($remover);

        Toast::flash(__('Du har lämnat :company.', ['company' => $company]));

        return $response;
    }
}
