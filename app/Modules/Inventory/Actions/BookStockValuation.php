<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\YearEndEntry;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The optional closing step of Lager (ADR-039): values the stock on the
 * last day of the year, saves the valuation as the underlag, and books the
 * difference between that value and the balance on the stock account
 * (1460, or the account chosen in the settings) against the change account
 * (4960 by default) on the last day, in series A. Booked again for a new
 * difference if the stock or the books change. Before the year's result.
 *
 * TODO(legal-verify): that the year-end stock change is booked on the
 * balance day against 4960/4910 rather than reversed, and the account pair.
 */
class BookStockValuation
{
    use GuardsInventory;

    public const string SERIES = 'A';

    public function __construct(private readonly BookVoucher $book) {}

    public function __invoke(User $user, Company $company, FiscalYear $fiscalYear): StockValuation
    {
        $this->guard($user, $company, 'manage');

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! ($fiscalYear->fresh() ?? $fiscalYear)->isOpen()) {
            throw ValidationException::withMessages(['valuation' => __('Räkenskapsåret :year är stängt.', ['year' => $fiscalYear->label()])]);
        }

        if ($fiscalYear->end_date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['valuation' => __('Lagret värderas och bokförs på årets sista dag, :date, eller senare.', ['date' => $fiscalYear->end_date->toDateString()])]);
        }

        $settings = InventorySettings::for($company);
        $stockNumber = (int) $settings['stock_account'];
        $changeNumber = (int) $settings['change_account'];

        return CurrentCompany::run($company, fn (): StockValuation => DB::transaction(function () use ($user, $company, $fiscalYear, $stockNumber, $changeNumber): StockValuation {
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::RESULT)->exists()) {
                throw ValidationException::withMessages(['valuation' => __('Årets resultat är redan bokat. Bokför lagerförändringen före resultatet.')]);
            }

            $stock = $this->account($stockNumber, $user, $company);
            $this->account($changeNumber, $user, $company);

            $report = StockValuationReport::at($company, $fiscalYear->end_date);
            $book = (int) (LedgerBalances::opening($fiscalYear)[$stock->id] ?? 0) + (int) (LedgerBalances::movements($fiscalYear)[$stock->id] ?? 0);
            $change = $report->valueOre - $book;
            $voucher = null;

            if ($change !== 0) {
                $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, __('Lagerförändring :year (lagervärde :value enligt :method)', [
                    'year' => $fiscalYear->label(),
                    'value' => Money::ofOre($report->valueOre)->format(),
                    'method' => $report->method->label(),
                ]), self::SERIES, [
                    ['account' => $stockNumber, 'amount' => $change],
                    ['account' => $changeNumber, 'amount' => -$change],
                ], VoucherSource::Closing, $fiscalYear);
            }

            $valuation = StockValuation::create([
                'fiscal_year_id' => $fiscalYear->id,
                'valuation_date' => $fiscalYear->end_date->toDateString(),
                'method' => $report->method->value,
                'cost_value_ore' => $report->costValueOre,
                'value_ore' => $report->valueOre,
                'book_value_ore' => $book,
                'booked_change_ore' => $change,
                'stock_account' => $stockNumber,
                'change_account' => $changeNumber,
                'lines' => $report->lines,
                'voucher_id' => $voucher?->id,
                'created_by' => $user->id,
            ]);

            YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => YearEndEntry::STOCK_VALUATION, 'amount_ore' => $change, 'voucher_id' => $voucher?->id, 'created_by' => $user->id]);

            Audit::record('closing.stock_valuation_booked', $fiscalYear, [
                'method' => $report->method->value,
                'value_ore' => $report->valueOre,
                'book_value_ore' => $book,
                'change_ore' => $change,
                'voucher' => $voucher?->reference(),
            ], $company, $user);

            return $valuation;
        }));
    }

    private function account(int $number, User $user, Company $company): Account
    {
        $account = Account::query()->where('number', $number)->first();

        if ($account instanceof Account) {
            return $account;
        }

        $name = InventorySettings::ACCOUNT_NAMES[$number] ?? throw ValidationException::withMessages(['valuation' => __('Konto :number finns inte i kontoplanen. Lägg till det först.', ['number' => $number])]);
        $account = Account::create(['number' => $number, 'name' => $name, 'type' => AccountType::forNumber($number)]);
        Audit::record('account.created', $account, ['number' => $number, 'name' => $name, 'reason' => 'stock_valuation'], $company, $user);

        return $account;
    }
}
