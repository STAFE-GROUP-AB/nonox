<?php

declare(strict_types=1);

namespace App\Modules\Saas;

use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Contracts\ProvidesDeadlines;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\Support\ContractVersions;
use App\Modules\Saas\Support\SaasDeadlines;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;

/**
 * NONOX SaaS (ADR-080): recurring revenue from the company's own agreements
 * and avtalsfakturering — MRR, ARR, the movements, churn, NRR and GRR,
 * cohorts and ARPA — in a ledger whose closed months never change. Offered
 * to every company, off by default (O10). Management metrics, not
 * bookkeeping (SA-R0): it posts nothing.
 */
final class SaasModule implements IsNew, Module, ProvidesDeadlines
{
    public const string KEY = 'saas';

    public const string VIEW = 'saas:view';

    public const string MANAGE = 'saas:manage';

    public const array PERMISSIONS = [self::VIEW, self::MANAGE];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('NONOX SaaS');
    }

    public function description(): string
    {
        return __('Återkommande intäkter från avtal och avtalsfakturering: MRR, ARR, rörelser per månad, churn, kvarhållen intäkt och kohorter. Stängda månader ändras aldrig; det som blir känt senare visas som rättelse. Nyckeltal för styrning, inte bokföring.');
    }

    public function icon(): string
    {
        return 'chart';
    }

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-05-01');
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
        return [];
    }

    /** ADR-080 §1: the approver neither sees nor manages; the bookkeeper and the auditor read. */
    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => [self::VIEW],
            'auditor' => [self::VIEW],
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
                'label' => __('Återkommande intäkter'),
                'route' => 'saas.overview',
                'permission' => self::VIEW,
                'active' => 'saas.*',
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Översikt'), 'route' => 'saas.overview', 'active' => ['saas.overview']],
                    ['label' => __('Rörelser'), 'route' => 'saas.movements', 'active' => ['saas.movements', 'saas.movements.show']],
                    ['label' => __('Kunder'), 'route' => 'saas.customers', 'active' => ['saas.customers', 'saas.customers.show']],
                    ['label' => __('Kohorter'), 'route' => 'saas.cohorts', 'active' => ['saas.cohorts']],
                    ['label' => __('Uppsagda'), 'route' => 'saas.scheduled', 'active' => ['saas.scheduled']],
                    ['label' => __('Rättelser'), 'route' => 'saas.corrections', 'active' => ['saas.corrections']],
                    ['label' => __('Månader'), 'route' => 'saas.months', 'active' => ['saas.months']],
                    ['label' => __('Källor'), 'route' => 'saas.sources', 'active' => ['saas.sources']],
                ],
            ],
        ];
    }

    public function settingsComponent(): ?string
    {
        return null;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Sidorna under Återkommande intäkter döljs och avtalsändringar registreras inte medan modulen är avstängd.'),
            __('Stängda månader och historiken finns kvar om modulen slås på igen. Ändringar som gjordes under tiden läses in från den dag modulen slås på.'),
        ]);
    }

    /**
     * Records every active or paused agreement as it stands (BACKFILL), fixes
     * the first ledger month the first time, and computes the months.
     */
    public function onEnable(Company $company, User $by): void
    {
        $modules = app(ModuleManager::class);
        $modules->seedSettings(self::KEY, $company, ['history_from' => Months::of(CarbonImmutable::today()->toDateString())]);

        $written = app(ContractVersions::class)->backfill($company, $by->id);

        Audit::record('saas.module_enabled', null, ['versions' => $written], $company, $by);

        RecomputeSaas::dispatch($company->id)->afterCommit();
    }

    public function onDisable(Company $company, User $by): void {}

    public function deadlineProvider(): DeadlineProvider
    {
        return app(SaasDeadlines::class);
    }
}
