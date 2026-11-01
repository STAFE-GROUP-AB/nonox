<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Company\CreateCompany;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Actions\Jetstream\UpdateTeamName;
use App\Domain\Company\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\TeamInvitation;
use App\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Jetstream's English paths are replaced by Swedish ones in routes/web.php
        // (AGENTS.md §11.4).
        Jetstream::ignoreRoutes();

        // Jetstream has no hook for its role change; ours also withdraws invitations.
        $this->app->bind(JetstreamUpdateTeamMemberRole::class, UpdateTeamMemberRole::class);
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

    /**
     * Core roles and permissions, plus those of every installed module. Module
     * roles are always registered; whether they grant anything depends on the
     * module being on for the company (User::hasTeamPermission).
     */
    public function configurePermissions(): void
    {
        $modules = $this->app->make(ModuleRegistry::class)->all();
        $modulePermissions = [];

        foreach ($modules as $module) {
            array_push($modulePermissions, ...$module->permissions());
        }

        Jetstream::permissions(array_values(array_unique([...CompanyRole::PERMISSIONS, ...$modulePermissions])));
        Jetstream::defaultApiTokenPermissions([]);

        foreach (CompanyRole::cases() as $role) {
            $permissions = $role->permissions();

            foreach ($modules as $module) {
                array_push($permissions, ...($module->rolePermissions()[$role->value] ?? []));
            }

            Jetstream::role($role->value, $role->label(), array_values(array_unique($permissions)))
                ->description($role->description());
        }

        foreach ($modules as $module) {
            foreach ($module->roles() as $key => $label) {
                Jetstream::role($key, $label, $module->rolePermissions()[$key] ?? [])
                    ->description(__('Roll i modulen :module.', ['module' => $module->name()]));
            }
        }
    }
}
