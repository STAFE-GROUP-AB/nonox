<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Actions\Accounting\CreateDefaultVoucherSeries;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\SeedPayCodes;
use App\Modules\Payroll\Livewire\PayrollSettingsForm;

/**
 * Lön (PAYROLL_MODULE.md Part B). Every rate, threshold and table it uses is
 * versioned data that must be verified before a run is allowed.
 */
final class PayrollModule implements Module
{
    public const string KEY = 'payroll';

    public const array PERMISSIONS = [
        'payroll:view', 'payroll:manage', 'payroll:run', 'payroll:approve', 'payroll:post',
        'payroll:view-sensitive', 'payroll:agi-submit', 'payroll:settings', 'payroll:archive-view',
        'employees:manage', 'self:payslips', 'self:absence', 'self:expenses', 'self:time',
    ];

    private const array SELF_SERVICE = ['self:payslips', 'self:absence', 'self:expenses', 'self:time'];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Lön');
    }

    public function description(): string
    {
        return __('Löner, lönespecifikationer, semester, sjuklön, förmåner, utlägg och arbetsgivardeklaration (AGI).');
    }

    public function icon(): string
    {
        return 'banknotes';
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
            'payroll_admin' => __('Löneadministratör'),
            'employee' => __('Anställd'),
        ];
    }

    /**
     * PAYROLL_MODULE.md B.2. The owner gets everything except sensitive data
     * (absence causes), which is granted to a named payroll administrator.
     */
    public function rolePermissions(): array
    {
        $payroll = array_values(array_diff(self::PERMISSIONS, self::SELF_SERVICE));

        return [
            'owner' => array_values(array_diff(self::PERMISSIONS, ['payroll:view-sensitive'])),
            'admin' => ['payroll:view', 'payroll:approve', 'payroll:archive-view', 'payroll:settings'],
            'accountant' => ['payroll:view', 'payroll:post', 'payroll:archive-view'],
            'auditor' => ['payroll:archive-view'],
            'payroll_admin' => $payroll,
            'employee' => self::SELF_SERVICE,
        ];
    }

    public function archivePermissions(): array
    {
        return ['payroll:archive-view'];
    }

    public function navigation(): array
    {
        return [
            ['label' => __('Lön'), 'route' => 'payroll.runs.index', 'permission' => 'payroll:view', 'active' => 'payroll.*'],
        ];
    }

    public function settingsComponent(): string
    {
        return PayrollSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    /**
     * TODO(P-8, P-11): blockers for unposted payroll runs, unsubmitted AGI and
     * unpaid approved expenses (PAYROLL_MODULE.md A.6) once those exist.
     */
    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Anställda som bara har rollen Anställd kommer inte längre åt företaget.'),
            __('Semestersaldon och semesterlöneskuld finns kvar i bokföringen.'),
            __('Påminnelser om lönekörningar stoppas.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, PayrollSettings::DEFAULTS);
        app(CreateDefaultVoucherSeries::class)($company);
        app(SeedPayCodes::class)($company);
    }

    public function onDisable(Company $company, User $by): void {}
}
