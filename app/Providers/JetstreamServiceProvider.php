<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Company\CreateCompany;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use App\Domain\Company\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\TeamInvitation;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Jetstream's English paths are replaced by Swedish ones in routes/web.php
        // (AGENTS.md §11.4).
        Jetstream::ignoreRoutes();
    }

    public function boot(): void
    {
        Jetstream::useTeamModel(Company::class);
        Jetstream::useMembershipModel(CompanyMembership::class);
        Jetstream::useTeamInvitationModel(TeamInvitation::class);

        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateCompany::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
    }

    private function configurePermissions(): void
    {
        Jetstream::permissions(CompanyRole::PERMISSIONS);
        Jetstream::defaultApiTokenPermissions([]);

        foreach (CompanyRole::cases() as $role) {
            Jetstream::role($role->value, $role->label(), $role->permissions())
                ->description($role->description());
        }
    }
}
