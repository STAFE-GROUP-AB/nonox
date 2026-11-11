<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Manufacturing\Livewire\ManufacturingSettingsForm;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * Tillverkning (ADR-071): bills of materials (stycklistor) with components
 * on several levels and operations (arbetssteg) with an hourly cost, the
 * copy a quote or order row keeps for itself, and the costing of material,
 * labour and overhead on quotes and orders. Needs Lager, whose structure
 * engine (ADR-073) stores the bills of materials. Off by default. Writes
 * nothing to the books: the costing is a management figure.
 *
 * Permissions: production:report sets up the workshop terminal (pairs
 * tablets, adds the people on it); a worker at the terminal is not a NONOX
 * user and can only report time. production:analyse sees the
 * post-calculation (ADR-071 §7). manufacturing:view sees bills of materials, operations and
 * the costing on quotes and orders; manufacturing:manage changes bills of
 * materials, operations and the settings. Changing a row's own copy needs
 * manufacturing:view and the right to change the quote or order.
 */
final class ManufacturingModule implements IsNew, Module
{
    public const string KEY = 'manufacturing';

    public const array PERMISSIONS = ['manufacturing:view', 'manufacturing:manage', 'production:report', 'production:analyse'];

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-06-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Tillverkning');
    }

    public function description(): string
    {
        return __('Stycklistor med komponenter och arbetssteg, en egen stycklista per offert- och orderrad, och kalkyl med material, arbete och marginal. Kräver Lager.');
    }

    public function icon(): string
    {
        return 'factory';
    }

    public function dependencies(): array
    {
        return [InventoryModule::KEY];
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public function roles(): array
    {
        return [];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => ['manufacturing:view', 'production:analyse'],
            'auditor' => ['manufacturing:view'],
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
                'label' => __('Tillverkning'),
                'route' => 'manufacturing.boms.index',
                'permission' => 'manufacturing:view',
                'active' => 'manufacturing.*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Stycklistor'), 'route' => 'manufacturing.boms.index', 'active' => ['manufacturing.boms.index', 'manufacturing.boms.show', 'manufacturing.boms.create']],
                    ['label' => __('Arbetssteg'), 'route' => 'manufacturing.operations', 'active' => ['manufacturing.operations']],
                    ...($user->hasTeamPermission($company, 'production:analyse') ? [
                        ['label' => __('Efterkalkyl'), 'route' => 'manufacturing.analysis', 'active' => ['manufacturing.analysis']],
                    ] : []),
                    ...($user->hasTeamPermission($company, 'production:report') ? [
                        ['label' => __('Verkstadsterminal'), 'route' => 'manufacturing.terminal.admin', 'active' => ['manufacturing.terminal.admin']],
                    ] : []),
                    ...($user->hasTeamPermission($company, 'manufacturing:manage') ? [
                        ['label' => __('Inställningar'), 'route' => 'manufacturing.settings', 'active' => ['manufacturing.settings']],
                    ] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return ManufacturingSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Tillverkning döljs, och offerter och ordrar visar ingen kalkyl medan modulen är avstängd.'),
            __('Stycklistor, arbetssteg och radernas egna stycklistor finns kvar om modulen slås på igen.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, ManufacturingSettings::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}
}
