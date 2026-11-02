<?php

declare(strict_types=1);

namespace App\Modules\Pos;

use App\Actions\Accounting\CreateDefaultVoucherSeries;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Livewire\PosSettingsForm;
use App\Modules\Pos\Models\PosConnection;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Kassakopplingar (ADR-042): the company's tills (Zettle, Onslip) under
 * Anslutningar → Kassa. Sales come in and take goods out of Lager, stock
 * changes go back to a till that keeps stock, and each day's sales become a
 * draft voucher in series K that a person posts. Needs Lager.
 *
 * It adds no permissions of its own: connecting a till needs
 * settings:manage, seeing the page and its log inventory:view, linking
 * unmatched lines inventory:manage and the daily draft vouchers:create.
 */
final class PosModule implements IsNew, Module
{
    public const string KEY = 'pos';

    public const string SERIES = 'K';

    /**
     * The accounts of the daily voucher, per company (BAS 2026 defaults
     * that exist in NONOX's chart templates). TODO(legal-verify): every
     * default below needs an accountant's check: card settlement on 1680
     * (BAS has 1580 for card receivables, not in the template), gift cards
     * and tips on 2890 (a gift card may be a single- or multi-purpose
     * voucher, ML 2023:200 ch. 3), card fees on 6570 without VAT.
     */
    public const array DEFAULTS = [
        'account_sales_2500' => 3001,
        'account_sales_1200' => 3002,
        'account_sales_600' => 3003,
        'account_sales_0' => 3004,
        'account_vat_2500' => 2611,
        'account_vat_1200' => 2621,
        'account_vat_600' => 2631,
        'account_payment_CARD' => 1680,
        'account_payment_CASH' => 1910,
        'account_payment_SWISH' => 1930,
        'account_payment_INVOICE' => 1510,
        'account_payment_GIFT_CARD' => 2890,
        'account_payment_OTHER' => 1680,
        'account_fees' => 6570,
        'account_fee_settlement' => 1680,
        'account_rounding' => 3740,
        'account_tips' => 2890,
        'auto_daily_draft' => true,
    ];

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
        return __('Kassakopplingar');
    }

    public function description(): string
    {
        return __('Koppla kassan (Zettle eller Onslip). Försäljningen dras från lagret, lagersaldot skickas tillbaka till kassan och varje dags försäljning blir ett verifikationsutkast i serie K som du bokför.');
    }

    public function icon(): string
    {
        return 'link';
    }

    public function dependencies(): array
    {
        return [InventoryModule::KEY];
    }

    public function permissions(): array
    {
        return [];
    }

    public function roles(): array
    {
        return [];
    }

    public function rolePermissions(): array
    {
        return [];
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
            ['label' => __('Kassa'), 'route' => 'pos.index', 'permission' => 'inventory:view', 'active' => 'pos.*'],
        ];
    }

    public function settingsComponent(): string
    {
        return PosSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $active = CurrentCompany::run($company, fn (): int => PosConnection::query()->where('status', '!=', ConnectionStatus::Disconnected->value)->count());
        $warnings = [__('Ingen mer försäljning hämtas och inget skickas till kassan. Det som redan har kommit in finns kvar.')];

        if ($active > 0) {
            $warnings[] = __(':count kassor är fortfarande kopplade. Koppla bort dem under Kassa om kassan inte längre ska lämna ut uppgifter.', ['count' => $active]);
        }

        return new ModuleCheck(warnings: $warnings);
    }

    /** Seeds the account settings and adds series K (existing vouchers are not touched). */
    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, self::DEFAULTS);
        app(CreateDefaultVoucherSeries::class)($company, [self::SERIES]);
    }

    public function onDisable(Company $company, User $by): void {}

    /**
     * The account a setting names, with the default when it is missing.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function account(array $settings, string $key): ?int
    {
        $value = $settings[$key] ?? self::DEFAULTS[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
