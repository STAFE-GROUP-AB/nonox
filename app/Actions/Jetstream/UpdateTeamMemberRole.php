<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Company\ReconcileFirmLinks;
use App\Actions\Company\SetDimensionScope;
use App\Actions\Company\WithdrawInvitationsSentBy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;

/**
 * Jetstream's role change, bound in its place (JetstreamServiceProvider). A
 * member whose new role takes away members:manage also loses the invitations
 * they sent that nobody has accepted yet, and a member whose new role can
 * never be limited loses their dimension scope (ADR-081 gap 10).
 */
class UpdateTeamMemberRole extends JetstreamUpdateTeamMemberRole
{
    public function __construct(
        private readonly WithdrawInvitationsSentBy $withdrawInvitations,
        private readonly ReconcileFirmLinks $reconcileFirmLinks,
    ) {}

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

            // Admin, accountant and auditor are never limited to cost centres or projects (ADR-081 gap 10).
            SetDimensionScope::liftForRole($team, $member->id, $role, $user instanceof User ? $user : null);

            if (! $couldManageMembers || $this->canManageMembers($member, $team)) {
                return;
            }

            ($this->withdrawInvitations)($team, $member, 'role_changed');
        });

        // A consultant who is no longer one may have been what linked the company to its firm (ADR-030).
        if ($team instanceof Company) {
            ($this->reconcileFirmLinks)($team, $user instanceof User ? $user : null);
        }
    }

    private function canManageMembers(User $member, Company $team): bool
    {
        return $member->hasTeamPermission(Company::query()->findOrFail($team->id), 'members:manage');
    }
}
