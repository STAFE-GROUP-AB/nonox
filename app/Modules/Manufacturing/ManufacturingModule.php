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
 * labour and overhead on quotes and orders, and production orders that
 * take components out of stock and receive the finished goods at their
 * manufacturing cost (§8). Needs Lager, whose structure engine (ADR-073)
 * stores the bills of materials. Off by default. Writes no voucher: the
 * costing is a management figure, and production orders meet the books
 * at the year-end stock valuation.
 *
 * Permissions: production:report sets up the workshop terminal (pairs
 * tablets, adds the people on it); a worker at the terminal is not a NONOX
 * user and can only report time. production:analyse sees the
 * post-calculation (ADR-071 §7). manufacturing:view sees bills of materials, operations and
 * the costing on quotes and orders; manufacturing:manage changes bills of
 * materials, operations, production orders and the settings. Changing a
 * row's own copy needs manufacturing:view and the right to change the
 * quote or order.
 *
 * The planning calendar (§13): manufacturing:view sees it; production:plan
 * moves, locks, splits and reprioritises work, applies scenarios and handles
 * deviations; production:confirm-dates confirms a delivery date to the
 * customer and sends the message.
 */
final class ManufacturingModule implements IsNew, Module
{
    public const string KEY = 'manufacturing';

    public const array PERMISSIONS = ['manufacturing:view', 'manufacturing:manage', 'production:report', 'production:analyse', 'production:plan', 'production:confirm-dates'];

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
        return __('Stycklistor med komponenter och arbetssteg, en egen stycklista per offert- och orderrad, kalkyl med material, arbete och marginal, och tillverkningsorder som tar komponenter ur lagret och tar emot de färdiga produkterna. Kräver Lager.');
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
            // Planering's scheduler plans the workshop too (ADR-071 §13.7).
            'scheduler' => ['manufacturing:view', 'production:plan'],
        ];
    }

    /** Withdrawing a photo, archiving a person and the register extract must work also with the module off (GDPR art. 7.3, 15). */
    public function archivePermissions(): array
    {
        return ['production:report'];
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
                    ['label' => __('Tillverkningsorder'), 'route' => 'manufacturing.productionOrders.index', 'active' => ['manufacturing.productionOrders.index', 'manufacturing.productionOrders.show', 'manufacturing.productionOrders.create']],
                    ['label' => __('Stycklistor'), 'route' => 'manufacturing.boms.index', 'active' => ['manufacturing.boms.index', 'manufacturing.boms.show', 'manufacturing.boms.create']],
                    ['label' => __('Arbetssteg'), 'route' => 'manufacturing.operations', 'active' => ['manufacturing.operations']],
                    ...($user->hasTeamPermission($company, 'production:analyse') ? [
                        ['label' => __('Efterkalkyl'), 'route' => 'manufacturing.analysis', 'active' => ['manufacturing.analysis']],
                    ] : []),
                    ...($user->hasTeamPermission($company, 'production:report') ? [
                        ['label' => __('Verkstadsterminal'), 'route' => 'manufacturing.terminal.admin', 'active' => ['manufacturing.terminal.admin']],
                    ] : []),
                    ['label' => __('Planering'), 'route' => 'manufacturing.planning.timeline', 'active' => ['manufacturing.planning.*']],
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
            __('Stycklistor, arbetssteg, tillverkningsorder och radernas egna stycklistor finns kvar om modulen slås på igen.'),
            __('Pågående tillverkningsorder räknas fortfarande som produkter i arbete i lagervärderingen vid bokslutet.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, ManufacturingSettings::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}
}
