<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Company\WithdrawInvitationsSentBy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;

/**
 * Jetstream's role change, bound in its place (JetstreamServiceProvider). A
 * member whose new role takes away members:manage also loses the invitations
 * they sent that nobody has accepted yet.
 */
class UpdateTeamMemberRole extends JetstreamUpdateTeamMemberRole
{
    public function __construct(private readonly WithdrawInvitationsSentBy $withdrawInvitations) {}

    /**
     * @param  mixed  $user
     * @param  mixed  $team
     * @param  int  $teamMemberId
     */
    public function update($user, $team, $teamMemberId, string $role): void
    {
        DB::transaction(function () use ($user, $team, $teamMemberId, $role): void {
            $member = User::find($teamMemberId);

            if (! $team instanceof Company || ! $member instanceof User) {
                parent::update($user, $team, $teamMemberId, $role);

                return;
            }

            $couldManageMembers = $this->canManageMembers($member, $team);

            parent::update($user, $team, $teamMemberId, $role);

            if (! $couldManageMembers || $this->canManageMembers($member, $team)) {
                return;
            }

            ($this->withdrawInvitations)($team, $member, 'role_changed');
        });
    }

    private function canManageMembers(User $member, Company $team): bool
    {
        return $member->hasTeamPermission(Company::query()->findOrFail($team->id), 'members:manage');
    }
}
