<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\StaffLedger\Livewire\StaffLedgerSettingsForm;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Tenancy\CurrentCompany;

/**
 * Personalliggare (ADR-022): check-in and check-out at a kiosk, with a
 * personal QR code or, where the employer allows it, in the phone with a
 * location check; an append-only ledger with a hash chain; and the control
 * visit view with exports. Free and built in.
 */
final class StaffLedgerModule implements Module
{
    public const string KEY = 'staff_ledger';

    public const array PERMISSIONS = [
        'staff-ledger:view',
        'staff-ledger:manage',
        'staff-ledger:view-sensitive',
        'staff-ledger:control-visit',
        'staff-ledger:settings',
        'staff-ledger:self',
    ];

    private const array MANAGER = [
        'staff-ledger:view', 'staff-ledger:manage', 'staff-ledger:view-sensitive', 'staff-ledger:control-visit',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Personalliggare');
    }

    public function description(): string
    {
        return __('Elektronisk personalliggare för restaurang, frisör, fordonsservice, tvätteri och grossister: incheckning på en surfplatta vid entrén, även utan internet, och en vy för Skatteverkets kontrollbesök.');
    }

    public function icon(): string
    {
        return 'identification';
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
        return [
            'staff_ledger_manager' => __('Personalansvarig'),
            'staff_ledger_staff' => __('Personal i personalliggaren'),
        ];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => [...self::MANAGER, 'staff-ledger:settings'],
            'admin' => [...self::MANAGER, 'staff-ledger:settings'],
            'accountant' => ['staff-ledger:view', 'staff-ledger:control-visit'],
            'auditor' => ['staff-ledger:view'],
            'staff_ledger_manager' => self::MANAGER,
            'staff_ledger_staff' => ['staff-ledger:self'],
        ];
    }

    /** The ledger must stay viewable and exportable for the retention period, also with the module off. */
    public function archivePermissions(): array
    {
        return ['staff-ledger:view', 'staff-ledger:control-visit', 'staff-ledger:view-sensitive'];
    }

    public function archiveNavigation(Company $company): array
    {
        if (! CurrentCompany::run($company, fn (): bool => Entry::query()->exists())) {
            return [];
        }

        return [
            ['label' => __('Personalliggare (arkiv)'), 'route' => 'staff-ledger.ledger', 'permission' => 'staff-ledger:view', 'active' => 'staff-ledger.ledger'],
        ];
    }

    public function navigation(): array
    {
        return [
            ['label' => __('Personalliggare'), 'route' => 'staff-ledger.overview', 'permission' => 'staff-ledger:view', 'active' => 'staff-ledger.*', 'children' => fn (User $user, Company $company): array => $this->pages($user, $company)],
            ['label' => __('Min incheckning'), 'route' => 'staff-ledger.mine', 'permission' => 'staff-ledger:self', 'active' => 'staff-ledger.mine'],
        ];
    }

    /** @return list<array{label: string, route: string, active?: list<string>}> the pages of Personalliggare, for the section in the sidebar */
    private function pages(User $user, Company $company): array
    {
        $pages = [['label' => __('Översikt'), 'route' => 'staff-ledger.overview', 'active' => ['staff-ledger.overview']]];
        $pages[] = ['label' => __('Liggaren'), 'route' => 'staff-ledger.ledger', 'active' => ['staff-ledger.ledger']];
        $pages[] = ['label' => __('Personal'), 'route' => 'staff-ledger.people', 'active' => ['staff-ledger.people', 'staff-ledger.people.*']];
        $pages[] = ['label' => __('Lokaler och kiosker'), 'route' => 'staff-ledger.locations', 'active' => ['staff-ledger.locations']];

        if ($user->hasTeamPermission($company, 'staff-ledger:manage') && app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company)) {
            $pages[] = ['label' => __('Tidrapporter'), 'route' => 'staff-ledger.proposals', 'active' => ['staff-ledger.proposals']];
        }

        if ($user->hasTeamPermission($company, 'staff-ledger:control-visit')) {
            $pages[] = ['label' => __('Kontrollbesök'), 'route' => 'staff-ledger.control-visit', 'active' => ['staff-ledger.control-visit', 'staff-ledger.control-visit.*']];
        }

        return $pages;
    }

    public function settingsComponent(): string
    {
        return StaffLedgerSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Kioskerna slutar ta emot incheckningar. Ett företag i en bransch med personalliggare måste ändå föra liggaren.'),
            __('Liggaren finns kvar och kan visas och exporteras vid kontrollbesök under hela bevarandetiden.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, StaffLedgerSettings::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}
}
