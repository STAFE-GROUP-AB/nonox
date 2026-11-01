<?php

declare(strict_types=1);

namespace App\Modules\Agreements;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\ImportAgreementRules;
use App\Modules\Agreements\Livewire\AgreementsSettingsForm;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;

/**
 * NONOX Avtal (ADR-033): the collective agreement in payroll. Agreement
 * facts ship as versioned, sourced data that a named person verifies before
 * a run may use them; the company records which agreement it is bound by
 * and each employment's lönegrupp and branschvana. Off by default; needs
 * payroll. Its pages are under Lön.
 */
final class AgreementsModule implements Module
{
    public const string KEY = 'agreements';

    public const array PERMISSIONS = ['agreements:view', 'agreements:manage', 'agreements:verify'];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Avtal');
    }

    public function description(): string
    {
        return __('Kollektivavtal i lönen: minimilöner, OB, övertid och mertid, semester, sjuklön, pension och avtalsförsäkringar enligt avtalet företaget är bundet av.');
    }

    public function icon(): string
    {
        return 'scale';
    }

    public function dependencies(): array
    {
        return [PayrollModule::KEY];
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    /** No roles of its own: the owner and the payroll administrator use it (ADR-033 §1). */
    public function roles(): array
    {
        return [];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'payroll_admin' => self::PERMISSIONS,
            'admin' => ['agreements:view'],
            'accountant' => ['agreements:view'],
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

    /** Avtal is a page under Lön (PayrollModule adds it while this module is on). */
    public function navigation(): array
    {
        return [];
    }

    public function settingsComponent(): string
    {
        return AgreementsSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Lönekörningar som inte är attesterade räknas om utan avtalets satser och kontroller. Attesterade lönekörningar ändras inte.'),
            __('Avtalsbindningen och anställningarnas lönegrupper finns kvar om modulen slås på igen.'),
        ]);
    }

    /** Seeds the settings and adds the shipped agreement versions that are missing, unverified. */
    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, AgreementsSettings::DEFAULTS);
        app(ImportAgreementRules::class)->importMissing();
    }

    public function onDisable(Company $company, User $by): void {}
}
