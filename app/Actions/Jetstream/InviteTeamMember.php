<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use App\Modules\Rules\AvailableRole;
use App\Support\Demo\DemoMode;
use App\Support\MailQuota;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     */
    public function invite(User $user, Company $team, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        DemoMode::refuse('email', $team, $user);

        $email = User::normaliseEmail($email);

        // An expired invitation must not block a new one to the same address.
        $team->teamInvitations()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('created_at', '<=', now()->subDays(TeamInvitationModel::VALID_DAYS))
            ->delete();

        $this->validate($team, $email, $role);

        InvitingTeamMember::dispatch($team, $email, $role);

        /** @var TeamInvitationModel $invitation */
        $invitation = $team->teamInvitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $user->id,
        ]);

        MailQuota::take($team, 'invitations');

        Mail::to($email)->send(new CompanyInvitationMail($invitation));
    }

    /**
     * Validate the invite member operation.
     */
    protected function validate(Company $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($team), [
            'email.unique' => __('This user has already been invited to the team.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for inviting a team member.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(Company $team): array
    {
        return array_filter([
            'email' => [
                'required', 'email',
                Rule::unique(Jetstream::teamInvitationModel())->where(function (Builder $query) use ($team) {
                    $query->where('team_id', $team->id);
                }),
            ],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role, new AvailableRole($team)]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     */
    protected function ensureUserIsNotAlreadyOnTeam(Company $team, string $email): Closure
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
