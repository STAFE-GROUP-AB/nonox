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
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Agi\AgiDeclaration;
use App\Support\Tenancy\CurrentCompany;

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
            'accountant' => ['payroll:view', 'payroll:post', 'payroll:agi-submit', 'payroll:archive-view'],
            'auditor' => ['payroll:archive-view'],
            'payroll_admin' => $payroll,
            'employee' => self::SELF_SERVICE,
        ];
    }

    public function archivePermissions(): array
    {
        return ['payroll:archive-view'];
    }

    /** The archive is shown only for a company that has approved payroll to keep. */
    public function archiveNavigation(Company $company): array
    {
        if (! CurrentCompany::run($company, fn (): bool => PayrollRun::query()->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])->exists())) {
            return [];
        }

        return [
            [
                'label' => __('Lönearkiv'),
                'route' => 'payroll.archive',
                'permission' => 'payroll:archive-view',
                'active' => 'payroll.archive*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Körningar och deklarationer'), 'route' => 'payroll.archive', 'active' => ['payroll.archive', 'payroll.archive.payslip', 'payroll.archive.agi*']],
                    ['label' => __('Rapporter'), 'route' => 'payroll.archive.reports'],
                ],
            ],
        ];
    }

    public function navigation(): array
    {
        return [
            [
                'label' => __('Lön'),
                'route' => 'payroll.runs.index',
                'permission' => 'payroll:view',
                'active' => ['payroll.index', 'payroll.runs.*', 'payroll.requests', 'payroll.employees*', 'payroll.agi*', 'payroll.reports', 'payroll.vacationYear', 'payroll.vacationLiability', 'payroll.rules', 'payroll.migration*', 'agreements.*'],
                'children' => fn (User $user, Company $company): array => $this->pages($user, $company),
            ],
        ];
    }

    /** @return list<array{label: string, route: string, active?: list<string>}> the pages of Lön, for the section in the sidebar */
    private function pages(User $user, Company $company): array
    {
        $pendingRequests = Absence::query()->where('status', 'REQUESTED')->count() + Expense::query()->where('status', 'SUBMITTED')->count();

        return [
            ['label' => __('Lönekörningar'), 'route' => 'payroll.runs.index'],
            ['label' => $pendingRequests > 0 ? __('Ansökningar (:count)', ['count' => $pendingRequests]) : __('Ansökningar'), 'route' => 'payroll.requests'],
            ['label' => __('Anställda'), 'route' => 'payroll.employees'],
            ['label' => __('Arbetsgivardeklaration'), 'route' => 'payroll.agi'],
            ['label' => __('Rapporter'), 'route' => 'payroll.reports'],
            ['label' => __('Semesterår'), 'route' => 'payroll.vacationYear'],
            ['label' => __('Semesterskuld'), 'route' => 'payroll.vacationLiability'],
            ['label' => __('Regler'), 'route' => 'payroll.rules'],
            // NONOX Avtal (ADR-033) lives under Lön while it is on.
            ...(app(ModuleManager::class)->isEnabled('agreements', $company) && $user->hasTeamPermission($company, 'agreements:view')
                ? [['label' => __('Avtal'), 'route' => 'agreements.index', 'active' => ['agreements.*']]]
                : []),
            ['label' => __('Flytt av lönedata'), 'route' => 'payroll.migration'],
            ['label' => __('Kom igång'), 'route' => 'payroll.index', 'active' => ['payroll.index']],
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
     * PAYROLL_MODULE.md A.6: payroll cannot be turned off with runs that are
     * not posted, months with pay that are not declared, or approved outlays
     * a run has taken on but not paid. Drafts and data stay as they are.
     */
    public function canDisable(Company $company): ModuleCheck
    {
        return CurrentCompany::run($company, function (): ModuleCheck {
            $blockers = [];
            $runs = PayrollRun::query()->where('status', '<>', PayrollRun::DRAFT)->orderBy('payment_date')->get();

            foreach ($runs->where('status', '!==', PayrollRun::POSTED) as $run) {
                $blockers[] = __('Lönekörningen :title är inte bokförd.', ['title' => $run->title()]);
            }

            $declared = AgiSubmission::query()->whereIn('status', [...AgiSubmission::REPORTED, AgiSubmission::CORRECTED])->pluck('period')->unique();

            foreach ($runs->whereIn('status', AgiDeclaration::DECLARED_STATUSES)->map(fn (PayrollRun $run): string => $run->payment_date->format('Y-m'))->unique() as $period) {
                if (! $declared->contains($period)) {
                    $blockers[] = __('Arbetsgivardeklarationen för :period är inte inlämnad.', ['period' => $period]);
                }
            }

            $outlays = Expense::query()->where('status', 'APPROVED')->whereNotNull('payroll_run_id')->count();

            if ($outlays > 0) {
                $blockers[] = $outlays === 1
                    ? __('Ett godkänt utlägg är inte utbetalt med lönen.')
                    : __(':count godkända utlägg är inte utbetalda med lönen.', ['count' => $outlays]);
            }

            return new ModuleCheck(blockers: $blockers, warnings: [
                __('Anställda som bara har rollen Anställd kommer inte längre åt företaget.'),
                __('Semestersaldon och semesterlöneskuld finns kvar i bokföringen.'),
                __('Påminnelser om lönekörningar stoppas.'),
                __('Lönekörningar, lönebesked, arbetsgivardeklarationer och rapporter finns kvar i Lönearkivet för den som har behörighet.'),
            ]);
        });
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, PayrollSettings::DEFAULTS);
        app(CreateDefaultVoucherSeries::class)($company);
        app(SeedPayCodes::class)($company);

        CurrentCompany::run($company, function (): void {
            if (! WorkSchedule::query()->exists()) {
                WorkSchedule::create(['name' => __('Heltid 40 h mån–fre'), 'pattern' => ['weeks' => [['8', '8', '8', '8', '8', '0', '0']]]]);
            }
        });
    }

    public function onDisable(Company $company, User $by): void {}
}
