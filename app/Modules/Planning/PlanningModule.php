<?php

declare(strict_types=1);

namespace App\Modules\Planning;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Contracts\ProvidesTodos;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Livewire\PlanningSettingsForm;
use App\Modules\Planning\Support\PlanningTodos;
use Carbon\CarbonImmutable;

/**
 * Planering (PLANNING_MODULE.md, ADR-037): the staff schedule with the
 * labour cost while planning. It plans the employees of Lön, reads the staff
 * ledger and the collective agreement when those modules are on, and never
 * writes to the ledger. Off by default; needs payroll.
 */
final class PlanningModule implements IsNew, Module, ProvidesTodos
{
    public const string KEY = 'planning';

    public const array PERMISSIONS = ['planning:view', 'planning:manage', 'planning:attest'];

    /** Min sida: the employee's own schedule, availability, requests and calendar link. */
    public const string SELF_SERVICE = 'self:schedule';

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-02-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Planering');
    }

    public function description(): string
    {
        return __('Schema för personalen med lönekostnaden synlig medan du planerar: pass, mallar, öppna pass, varningar för vilotider och personalkostnad mot dagens försäljning i bokföringen.');
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function dependencies(): array
    {
        return [PayrollModule::KEY];
    }

    public function permissions(): array
    {
        return [...self::PERMISSIONS, self::SELF_SERVICE];
    }

    public function roles(): array
    {
        return ['scheduler' => __('Schemaläggare')];
    }

    /** PLANNING_MODULE.md §2: employees see only their own schedule, in Min sida. */
    public function rolePermissions(): array
    {
        return [
            'owner' => [...self::PERMISSIONS, self::SELF_SERVICE],
            'admin' => [...self::PERMISSIONS, self::SELF_SERVICE],
            'payroll_admin' => [...self::PERMISSIONS, self::SELF_SERVICE],
            'scheduler' => ['planning:view', 'planning:manage', self::SELF_SERVICE],
            'employee' => [self::SELF_SERVICE],
        ];
    }

    public function archivePermissions(): array
    {
        return [];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [
            [
                'label' => __('Planering'),
                'route' => 'planning.schedule',
                'permission' => 'planning:view',
                'active' => 'planning.*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Schema'), 'route' => 'planning.schedule', 'active' => ['planning.schedule']],
                    ['label' => __('Mallar'), 'route' => 'planning.templates', 'active' => ['planning.templates']],
                    ...($user->hasTeamPermission($company, 'planning:attest') ? [['label' => __('Tid att attestera'), 'route' => 'planning.attest', 'active' => ['planning.attest']]] : []),
                    ['label' => __('Öppna pass och byten'), 'route' => 'planning.requests', 'active' => ['planning.requests']],
                    ['label' => __('Bemanning och kostnad'), 'route' => 'planning.staffing', 'active' => ['planning.staffing']],
                    ['label' => __('Inställningar'), 'route' => 'planning.settings', 'active' => ['planning.settings']],
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return PlanningSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Schemat döljs och inga fler aviseringar om pass skickas.'),
            __('Pass, mallar och avdelningar finns kvar om modulen slås på igen.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, PlanningSettings::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}

    /** Översikt's "Att göra" (ADR-027). */
    public function todoProvider(): TodoProvider
    {
        return app(PlanningTodos::class);
    }
}
