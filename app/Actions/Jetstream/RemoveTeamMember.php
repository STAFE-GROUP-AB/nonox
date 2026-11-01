<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Company\WithdrawInvitationsSentBy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Events\TeamMemberRemoved;

class RemoveTeamMember implements RemovesTeamMembers
{
    public function __construct(private readonly WithdrawInvitationsSentBy $withdrawInvitations) {}

    /**
     * Remove the team member from the given team, and withdraw the invitations
     * they sent that nobody has accepted yet.
     */
    public function remove(User $user, Company $team, User $teamMember): void
    {
        $this->authorize($user, $team, $teamMember);

        $this->ensureUserDoesNotOwnTeam($teamMember, $team);

        DB::transaction(function () use ($team, $teamMember): void {
            $team->removeUser($teamMember);

            ($this->withdrawInvitations)($team, $teamMember, 'member_removed');
        });

        TeamMemberRemoved::dispatch($team, $teamMember);
    }

    /**
     * Authorize that the user can remove the team member.
     */
    protected function authorize(User $user, Company $team, User $teamMember): void
    {
        if (! Gate::forUser($user)->check('removeTeamMember', $team) &&
            $user->id !== $teamMember->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ensure that the currently authenticated user does not own the team.
     */
    protected function ensureUserDoesNotOwnTeam(User $teamMember, Company $team): void
    {
        if ($teamMember->id === $team->user_id) {
            throw ValidationException::withMessages([
                'team' => [__('You may not leave a team that you created.')],
            ])->errorBag('removeTeamMember');
        }
    }
}
