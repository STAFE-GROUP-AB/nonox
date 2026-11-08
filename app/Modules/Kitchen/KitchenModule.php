<?php

declare(strict_types=1);

namespace App\Modules\Kitchen;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Kitchen\Livewire\KitchenSettingsForm;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * Kök (ADR-073): recipes with portion cost and margin, the menu, waste
 * from the phone, staff meals, and the variance between what the till's
 * sales should have used and what the stock counts show. Needs Lager; uses
 * Kassakopplingar and Lön when they are on. Off by default. Writes nothing
 * to the books: waste and staff meals are stock movements, and the books
 * meet the stock at the year-end valuation.
 *
 * Permissions: kitchen:view sees recipes, dishes and the logs without
 * costs; kitchen:register registers waste and staff meals; kitchen:manage
 * changes recipes, sees costs and margins, the variance report and the
 * settings. The role Kökspersonal registers and never sees a price.
 */
final class KitchenModule implements IsNew, Module
{
    public const string KEY = 'kitchen';

    public const array PERMISSIONS = ['kitchen:view', 'kitchen:register', 'kitchen:manage'];

    public const string ROLE = 'kitchen_staff';

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-05-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Kök');
    }

    public function description(): string
    {
        return __('Recept med portionskostnad och marginal, menyn, svinn från mobilen, personalmat och avvikelsen mellan sålt och inventerat. Kräver Lager.');
    }

    public function icon(): string
    {
        return 'utensils';
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
        return [self::ROLE => __('Kökspersonal')];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => self::PERMISSIONS,
            'auditor' => ['kitchen:view'],
            self::ROLE => ['kitchen:view', 'kitchen:register'],
            // The payroll administrator approves staff-meal benefits into the run (ADR-073 §7, Lead O5).
            'payroll_admin' => ['kitchen:view'],
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
                'label' => __('Kök'),
                'route' => 'kitchen.recipes.index',
                'permission' => 'kitchen:view',
                'active' => 'kitchen.*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Recept'), 'route' => 'kitchen.recipes.index', 'active' => ['kitchen.recipes.index', 'kitchen.recipes.show', 'kitchen.recipes.create']],
                    ['label' => __('Meny'), 'route' => 'kitchen.menu', 'active' => ['kitchen.menu']],
                    ['label' => __('Svinn'), 'route' => 'kitchen.waste', 'active' => ['kitchen.waste']],
                    ['label' => __('Personalmat'), 'route' => 'kitchen.meals', 'active' => ['kitchen.meals']],
                    ...($user->hasTeamPermission($company, 'kitchen:manage') ? [
                        ['label' => __('Avvikelser'), 'route' => 'kitchen.variance', 'active' => ['kitchen.variance']],
                        ['label' => __('Inställningar'), 'route' => 'kitchen.settings', 'active' => ['kitchen.settings']],
                    ] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return KitchenSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Kök döljs, och inga försäljningar dras från lagret via recepten medan modulen är avstängd.'),
            __('Recept, svinnloggen och personalmaten finns kvar om modulen slås på igen.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        $modules = app(ModuleManager::class);
        $modules->seedSettings(self::KEY, $company, KitchenSettings::DEFAULTS);

        // Turned on again with drawing on sale: only sales from today are drawn, never the backlog (review P3.5).
        if (KitchenSettings::drawsOnSale($company)) {
            $modules->updateSettings(self::KEY, $company, $by, ['draw_on_sale_from' => CarbonImmutable::today()->toDateString()]);
        }
    }

    public function onDisable(Company $company, User $by): void {}
}
