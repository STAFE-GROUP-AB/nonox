<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Inventory\Actions\LabelTemplates;
use App\Modules\Inventory\Livewire\InventorySettingsForm;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * Lager (ADR-039): articles kept in stock, locations, the movement ledger,
 * goods receipts, pick lists, stocktakes and the year-end valuation. It
 * writes to the books only when someone books the stock change at the year
 * end. Off by default; needs no other module.
 *
 * Permissions: inventory:view sees stock; inventory:count handles goods
 * (receive, pick, move, count); inventory:manage changes articles,
 * locations and costs and sees values; inventory:settings changes the
 * settings.
 */
final class InventoryModule implements IsNew, Module
{
    public const string KEY = 'inventory';

    public const array PERMISSIONS = ['inventory:view', 'inventory:count', 'inventory:manage', 'inventory:settings'];

    public const string ROLE = 'stock_clerk';

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-03-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Lager');
    }

    public function description(): string
    {
        return __('Lagerförda artiklar med EAN, lagerställen i hyllor och fack, inleveranser, plocklistor, inventering och lagervärdet vid bokslutet.');
    }

    public function icon(): string
    {
        return 'box';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public function roles(): array
    {
        return [self::ROLE => __('Lagerpersonal')];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => ['inventory:view', 'inventory:count', 'inventory:manage'],
            'auditor' => ['inventory:view'],
            self::ROLE => ['inventory:view', 'inventory:count'],
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
                'label' => __('Lager'),
                'route' => 'inventory.overview',
                'permission' => 'inventory:view',
                'active' => 'inventory.*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Översikt'), 'route' => 'inventory.overview', 'active' => ['inventory.overview']],
                    ['label' => __('Skanna'), 'route' => 'inventory.scanner', 'active' => ['inventory.scanner']],
                    ['label' => __('Artiklar'), 'route' => 'inventory.articles', 'active' => ['inventory.articles']],
                    ['label' => __('Lagerställen'), 'route' => 'inventory.locations', 'active' => ['inventory.locations']],
                    ['label' => __('Inleveranser'), 'route' => 'inventory.receipts', 'active' => ['inventory.receipts']],
                    ['label' => __('Plocklistor'), 'route' => 'inventory.pickLists', 'active' => ['inventory.pickLists', 'inventory.pickLists.pdf']],
                    ['label' => __('Inventering'), 'route' => 'inventory.counts', 'active' => ['inventory.counts']],
                    ['label' => __('Rapporter'), 'route' => 'inventory.reports', 'active' => ['inventory.reports']],
                    ...($user->hasTeamPermission($company, 'inventory:settings') ? [['label' => __('Inställningar'), 'route' => 'inventory.settings', 'active' => ['inventory.settings', 'inventory.labels', 'inventory.labels.sample']]] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return InventorySettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Lagret döljs och inga lagerrörelser registreras medan modulen är avstängd.'),
            __('Artiklar, lagerställen, rörelser och värderingar finns kvar om modulen slås på igen.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, InventorySettings::DEFAULTS);
        app(LabelTemplates::class)->ensureDefaults($company, $by);
    }

    public function onDisable(Company $company, User $by): void {}
}
