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
        $registry = $this->app->make(ModuleRegistry::class);
        // A refused module must never reach the roles (ADR-079 §2.2).
        $registry->verify();
        $modules = $registry->all();
        $modulePermissions = [];

        foreach ($modules as $module) {
            array_push($modulePermissions, ...$registry->permissionsOf($module));
        }

        Jetstream::permissions(array_values(array_unique([...CompanyRole::PERMISSIONS, ...$modulePermissions])));
        Jetstream::defaultApiTokenPermissions([]);

        foreach (CompanyRole::cases() as $role) {
            $permissions = $role->permissions();

            foreach ($modules as $module) {
                array_push($permissions, ...($registry->rolePermissionsOf($module)[$role->value] ?? []));
            }

            Jetstream::role($role->value, $role->label(), array_values(array_unique($permissions)))
                ->description($role->description());
        }

        foreach ($modules as $module) {
            $labels = $module->roles();

            foreach ($registry->roleKeysOf($module) as $key) {
                // A module may give permissions to another module's role (NONOX Avtal to the payroll administrator).
                $permissions = array_merge([], ...array_values(array_map(fn ($each): array => $registry->rolePermissionsOf($each)[$key] ?? [], $modules)));

                Jetstream::role($key, $labels[$key] ?? $key, array_values(array_unique($permissions)))
                    ->description(__('Roll i modulen :module.', ['module' => $module->name()]));
            }
        }
    }
}
