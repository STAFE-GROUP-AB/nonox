<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\SaveLabourKind;
use App\Modules\WorkOrders\Livewire\SettingsForm;
use Carbon\CarbonImmutable;

/**
 * Arbetsorder (ADR-074): work orders that collect time and material on site
 * and become the invoice basis, and change and additional work (ÄTA) that
 * the customer approves with a price before the work is done. Requires
 * nothing; uses Lager when it is on. Off by default. Turning it off hides
 * the screens and keeps every record; decided ÄTA stay reachable through
 * work-orders:view for the retention period.
 */
final class WorkOrdersModule implements IsNew, Module
{
    public const string KEY = 'work_orders';

    public const string VIEW = 'work-orders:view';

    public const string MANAGE = 'work-orders:manage';

    public const string REPORT = 'work-orders:report';

    public const string SEND = 'change-orders:send';

    public const array PERMISSIONS = [self::VIEW, self::MANAGE, self::REPORT, self::SEND];

    /** ADR-074 O3: a NONOX user who reports on the work orders assigned to them, and sees only those. */
    public const string FIELD_WORKER = 'field_worker';

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
        return __('Arbetsorder');
    }

    public function description(): string
    {
        return __('Arbetsorder för hantverkare: tid och material på plats, ÄTA som kunden godkänner med pris innan arbetet görs, och fakturaunderlag med ROT, RUT och omvänd byggmoms rätt från början.');
    }

    public function icon(): string
    {
        return 'wrench';
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
        return [self::FIELD_WORKER => __('Utförare')];
    }

    /** ADR-074 §1. */
    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => [self::VIEW, self::MANAGE],
            'bookkeeper' => [self::VIEW, self::MANAGE, self::SEND],
            'auditor' => [self::VIEW],
            self::FIELD_WORKER => [self::VIEW, self::REPORT],
        ];
    }

    /** Decided ÄTA and their evidence are räkenskapsinformation (AO-R25): readable while the module is off. */
    public function archivePermissions(): array
    {
        return [self::VIEW];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [
            [
                'label' => __('Arbetsorder'),
                'route' => 'workOrders.index',
                'permission' => self::VIEW,
                'active' => 'workOrders.*',
                'children' => fn (User $user, Company $company): array => [
                    ...($user->hasTeamPermission($company, self::REPORT) ? [['label' => __('Mina arbetsorder'), 'route' => 'workOrders.mine', 'active' => ['workOrders.mine', 'workOrders.field']]] : []),
                    ['label' => __('Alla arbetsorder'), 'route' => 'workOrders.index', 'active' => ['workOrders.index', 'workOrders.show', 'workOrders.create', 'workOrders.edit']],
                    ...($user->hasTeamPermission($company, self::MANAGE) ? [
                        ['label' => __('Pågående arbeten'), 'route' => 'workOrders.ongoing', 'active' => ['workOrders.ongoing']],
                        ['label' => __('Inställningar'), 'route' => 'workOrders.settings', 'active' => ['workOrders.settings']],
                    ] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return SettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Arbetsordrarna döljs och utförarna kan inte rapportera tid eller material.'),
            __('Arbetsorder, tid, material och ÄTA finns kvar om modulen slås på igen. Beslutade ÄTA går fortfarande att läsa.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, WorkOrdersSettings::DEFAULTS);
        app(SaveLabourKind::class)->seedDefaults($company);
    }

    public function onDisable(Company $company, User $by): void {}
}
