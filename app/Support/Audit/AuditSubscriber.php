<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Events\TeamMemberUpdated;

/**
 * Records sign-ins and changes to who works in a company in the
 * behandlingshistorik (AGENTS.md §6.6).
 */
final class AuditSubscriber
{
    public function signedIn(Login $event): void
    {
        Audit::record('auth.signed_in', $this->user($event->user));
    }

    public function signedOut(Logout $event): void
    {
        if ($event->user !== null) {
            Audit::record('auth.signed_out', $this->user($event->user));
        }
    }

    public function memberAdded(TeamMemberAdded $event): void
    {
        Audit::record('company.member_added', $event->user, [
            'email' => $event->user->email,
            'role' => $this->role($event->team, $event->user),
        ], $event->team);
    }

    public function memberRemoved(TeamMemberRemoved $event): void
    {
        Audit::record('company.member_removed', $event->user, [
            'email' => $event->user->email,
        ], $event->team);
    }

    public function memberUpdated(TeamMemberUpdated $event): void
    {
        Audit::record('company.member_role_changed', $event->user, [
            'email' => $event->user->email,
            'role' => $this->role($event->team, $event->user),
        ], $event->team);
    }

    public function invitationSent(InvitingTeamMember $event): void
    {
        Audit::record('company.invitation_sent', null, [
            'email' => $event->email,
            'role' => $event->role,
        ], $event->team);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'signedIn',
            Logout::class => 'signedOut',
            TeamMemberAdded::class => 'memberAdded',
            TeamMemberRemoved::class => 'memberRemoved',
            TeamMemberUpdated::class => 'memberUpdated',
            InvitingTeamMember::class => 'invitationSent',
        ];
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    private function role(Company $company, User $user): ?string
    {
        $role = CompanyMembership::query()
            ->where('team_id', $company->id)
            ->where('user_id', $user->id)
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
