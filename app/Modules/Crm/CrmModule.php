<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Contracts\ProvidesTodos;
use App\Modules\Crm\Actions\EnsureCustomerAccounts;
use App\Modules\Crm\Actions\EnsurePipelineDefaults;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Support\CrmTodos;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * CRM (ADR-043): the accounts the business deals with, prospects and
 * customers, their people, the activities with a next step, and the key
 * figures read from the ledger. While it is on, /kunder is the CRM; while it
 * is off, the plain customer register. It never writes to the ledger: a
 * prospect becomes a customer through the customer register's own action.
 *
 * Permissions: crm:view sees accounts, people, activities and key figures;
 * crm:manage changes them; crm:settings changes the settings. The role
 * Säljare (sales) gets view and manage: it sees invoiced, unpaid and
 * payment time per company customer, but no invoices, vouchers or reports.
 * For a private person (kind PERSON) it sees no key figures and no ledger
 * entries on the timeline at all, only the CRM's own activities.
 */
final class CrmModule implements IsNew, Module, ProvidesTodos
{
    public const string KEY = 'crm';

    public const array PERMISSIONS = ['crm:view', 'crm:manage', 'crm:settings'];

    public const string ROLE = 'sales';

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
        return __('CRM');
    }

    public function description(): string
    {
        return __('Kunder och prospekt med kontaktpersoner, aktiviteter med nästa steg och nyckeltal direkt ur bokföringen: fakturerat, obetalt, betaltid och täckningsbidrag. Affärer i en säljtratt med prognos, mål per säljare och vinn tillbaka-påminnelser. Ersätter kundregistret medan modulen är på.');
    }

    public function icon(): string
    {
        return 'users';
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
        return [self::ROLE => __('Säljare')];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => self::PERMISSIONS,
            'auditor' => ['crm:view'],
            self::ROLE => ['crm:view', 'crm:manage'],
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
                'label' => __('CRM'),
                'route' => 'crm.dashboard',
                'permission' => 'crm:view',
                'active' => ['crm.*', 'invoicing.customers'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Översikt'), 'route' => 'crm.dashboard', 'active' => ['crm.dashboard']],
                    ['label' => __('Affärer'), 'route' => 'crm.deals', 'active' => ['crm.deals', 'crm.deals.show']],
                    ['label' => __('Kunder och prospekt'), 'route' => 'invoicing.customers', 'active' => ['invoicing.customers', 'crm.accounts.show']],
                    ['label' => __('Mina aktiviteter'), 'route' => 'crm.activities', 'active' => ['crm.activities']],
                    ['label' => __('Prognos och mål'), 'route' => 'crm.forecast', 'active' => ['crm.forecast']],
                    ['label' => __('Personer'), 'route' => 'crm.people', 'active' => ['crm.people', 'crm.people.show']],
                    ...($user->hasTeamPermission($company, 'crm:settings') ? [['label' => __('Inställningar'), 'route' => 'crm.settings', 'active' => ['crm.settings']]] : []),
                ],
            ],
        ];
    }

    public function settingsComponent(): string
    {
        return CrmSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Kunder visas som det vanliga kundregistret igen, och aktiviteter, personer och prospekt döljs.'),
            __('Allt finns kvar om modulen slås på igen. Kunderna i bokföringen påverkas inte.'),
        ]);
    }

    /** Seeds the settings, gives every existing customer an account and creates the default pipeline; all idempotent. */
    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, CrmSettings::DEFAULTS);
        app(EnsureCustomerAccounts::class)($company, $by);
        app(EnsurePipelineDefaults::class)($company);
    }

    public function onDisable(Company $company, User $by): void {}

    /** Översikt's "Att göra" (ADR-027). */
    public function todoProvider(): TodoProvider
    {
        return app(CrmTodos::class);
    }
}
