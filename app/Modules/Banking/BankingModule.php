<?php

declare(strict_types=1);

namespace App\Modules\Banking;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Livewire\BankingSettingsForm;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\BankingTodos;
use App\Modules\Contracts\Module;
use App\Modules\Contracts\ProvidesTodos;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * NONOX Banking (ADR-034): the company connects its bank accounts through
 * an open-banking provider (PSD2 account information), and transactions
 * and balances flow in for the bank reconciliation and incoming payments.
 * Read only: payments still go as pain.001 files signed in the bank.
 */
final class BankingModule implements Module, ProvidesTodos
{
    public const string KEY = 'banking';

    public const array PERMISSIONS = ['banking:view', 'banking:manage'];

    public const array DEFAULTS = [
        // Fetches per day without the user present; PSD2 allows a bank to cap it at four (RTS art. 36(5)(b)).
        'fetches_per_day' => 2,
        'reconsent_reminders' => true,
        'reconsent_reminder_days' => 14,
    ];

    public const array LIMITS = [
        'fetches_per_day' => [1, 4],
        'reconsent_reminder_days' => [1, 60],
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Bank');
    }

    public function description(): string
    {
        return __('Koppla företagets bankkonton med BankID. Transaktioner och saldon hämtas automatiskt till bankavstämningen och inbetalningarna, i stället för att du laddar upp filer. NONOX kan bara läsa, aldrig betala.');
    }

    public function icon(): string
    {
        return 'bank';
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

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => ['banking:view'],
            'auditor' => ['banking:view'],
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
            ['label' => __('Bank'), 'route' => 'banking.index', 'permission' => 'banking:view', 'active' => 'banking.*'],
        ];
    }

    public function settingsComponent(): string
    {
        return BankingSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return BankDataProviders::isConfigured()
            ? ModuleCheck::allow()
            : new ModuleCheck(blockers: [__('Bankkopplingen är inte inställd på den här installationen. Den som driftar NONOX behöver ett avtal med en leverantör av öppen bankdata.')]);
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $active = CurrentCompany::run($company, fn (): int => BankConnection::query()->where('status', ConnectionStatus::Active->value)->count());
        $warnings = [__('Inga fler transaktioner hämtas. Det som redan har hämtats finns kvar.')];

        if ($active > 0) {
            $warnings[] = __(':count bankkopplingar är fortfarande aktiva. Koppla bort dem under Bank om banken inte längre ska lämna ut uppgifter.', ['count' => $active]);
        }

        return new ModuleCheck(warnings: $warnings);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, self::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function setting(array $settings, string $key): int|bool
    {
        $value = $settings[$key] ?? self::DEFAULTS[$key];

        if (is_bool(self::DEFAULTS[$key])) {
            return (bool) $value;
        }

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];
    }

    /** Översikt's "Att göra" (ADR-027). */
    public function todoProvider(): TodoProvider
    {
        return app(BankingTodos::class);
    }
}
