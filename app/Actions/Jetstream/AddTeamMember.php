<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Company;
use App\Models\User;
use App\Modules\Rules\AvailableRole;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     */
    public function add(User $user, Company $team, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $email = User::normaliseEmail($email);

        $this->validate($team, $email, $role);

        $newTeamMember = User::query()->byEmail($email)->orderBy('id')->firstOrFail();

        AddingTeamMember::dispatch($team, $newTeamMember);

        $team->users()->attach(
            $newTeamMember, ['role' => $role]
        );

        TeamMemberAdded::dispatch($team, $newTeamMember);
    }

    /**
     * Validate the add member operation.
     */
    protected function validate(Company $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($team))->after(
            $this->ensureUserExists($email)
        )->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for adding a team member.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(Company $team): array
    {
        return array_filter([
            'email' => ['required', 'email'],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role, new AvailableRole($team)]
                            : null,
        ]);
    }

    /**
     * Ensure that a user has the address, whatever its case.
     */
    protected function ensureUserExists(string $email): Closure
    {
        return function ($validator) use ($email) {
            $validator->errors()->addIf(
                ! $validator->errors()->has('email') && ! User::query()->byEmail($email)->exists(),
                'email',
                __('We were unable to find a registered user with this email address.')
            );
        };
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
