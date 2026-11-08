<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\YearEndEntry;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The optional closing step of Lager (ADR-039): values the stock on the
 * last day of the year, saves the valuation as the underlag, and books the
 * difference between that value and the balance on each stock account
 * against its change account on the last day, in series A, one voucher.
 * Since ADR-073 §9 there is one account pair per stock class: raw
 * materials (1410/4910 by default) and goods for resale (the company's
 * original pair, 1460/4960 by default); a company that never chose a class
 * books one pair as before. A K2 company that has chosen the 97 % rule
 * (K2 punkt 12.5) takes each class up at 97 % of its cost, refused when
 * any article is written down to its net realisable value. Booked again
 * for a new difference if the stock or the books change. Before the
 * year's result.
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
        $rule97 = InventorySettings::usesRule97($company, $fiscalYear);

        return CurrentCompany::run($company, fn (): StockValuation => DB::transaction(function () use ($user, $company, $fiscalYear, $settings, $rule97): StockValuation {
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::RESULT)->exists()) {
                throw ValidationException::withMessages(['valuation' => __('Årets resultat är redan bokat. Bokför lagerförändringen före resultatet.')]);
            }

            $plan = self::plan($company, $fiscalYear);
            $report = $plan['report'];
            $rows = [];
            $classes = [];

            foreach ($plan['groups'] as $group) {
                $this->account($group['stock_account'], $user, $company);
                $this->account($group['change_account'], $user, $company);

                if ($group['change_ore'] !== 0) {
                    $rows[] = ['account' => $group['stock_account'], 'amount' => $group['change_ore']];
                    $rows[] = ['account' => $group['change_account'], 'amount' => -$group['change_ore']];
                }

                $classes[] = $group;
            }

            $value = $plan['value_ore'];
            $book = $plan['book_value_ore'];
            $change = $value - $book;
            $voucher = null;

            if ($rows !== []) {
                $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, __('Lagerförändring :year (lagervärde :value enligt :method)', [
                    'year' => $fiscalYear->label(),
                    'value' => Money::ofOre($value)->format(),
                    'method' => $report->method->label().($rule97 ? ', '.__('97-procentsregeln') : ''),
                ]), self::SERIES, $rows, VoucherSource::Closing, $fiscalYear);
            }

            $valuation = StockValuation::create([
                'fiscal_year_id' => $fiscalYear->id,
                'valuation_date' => $fiscalYear->end_date->toDateString(),
                'method' => $report->method->value,
                'cost_value_ore' => $report->costValueOre,
                'value_ore' => $value,
                'book_value_ore' => $book,
                'booked_change_ore' => $change,
                'stock_account' => (int) $settings['stock_account'],
                'change_account' => (int) $settings['change_account'],
                'lines' => $report->lines,
                'classes' => $classes,
                'rule_97' => $rule97,
                'voucher_id' => $voucher?->id,
                'created_by' => $user->id,
            ]);

            YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => YearEndEntry::STOCK_VALUATION, 'amount_ore' => $change, 'voucher_id' => $voucher?->id, 'created_by' => $user->id]);

            Audit::record('closing.stock_valuation_booked', $fiscalYear, [
                'method' => $report->method->value,
                'value_ore' => $value,
                'book_value_ore' => $book,
                'change_ore' => $change,
                'rule_97' => $rule97,
                'accounts' => array_column($classes, 'stock_account'),
                'voucher' => $voucher?->reference(),
            ], $company, $user);

            return $valuation;
        }));
    }

    /**
     * What booking would do, without writing anything: the valuation report,
     * and per stock account its classes, cost, value, the balance on the
     * account in the books, and the change to book. For the page and for
     * the booking itself. Refuses the 97 % rule when an article is written
     * down.
     *
     * @return array{report: StockValuationReport, rule_97: bool, value_ore: int, book_value_ore: int, change_ore: int, groups: list<array{classes: list<string>, stock_account: int, change_account: int, cost_value_ore: int, value_ore: int, book_value_ore: int, change_ore: int}>}
     */
    public static function plan(Company $company, FiscalYear $fiscalYear): array
    {
        $rule97 = InventorySettings::usesRule97($company, $fiscalYear);
        $pairs = InventorySettings::pairs($company);

        return CurrentCompany::run($company, function () use ($company, $fiscalYear, $rule97, $pairs): array {
            $report = StockValuationReport::at($company, $fiscalYear->end_date);

            if ($rule97 && collect($report->lines)->contains(fn (array $line): bool => $line['written_down'])) {
                throw ValidationException::withMessages(['valuation' => __('97-procentsregeln kan inte användas när någon vara är nedskriven till nettoförsäljningsvärde (K2 punkt 12.5). Stäng av regeln eller ta bort nedskrivningen.')]);
            }

            $accounts = Account::query()->whereIn('number', array_unique(array_merge(...array_values($pairs))))->pluck('id', 'number');
            $opening = LedgerBalances::opening($fiscalYear);
            $movements = LedgerBalances::movements($fiscalYear);
            $balance = function (int $number) use ($accounts, $opening, $movements): int {
                $id = $accounts->get($number);

                return $id === null ? 0 : (int) ($opening[$id] ?? 0) + (int) ($movements[$id] ?? 0);
            };

            $groups = self::groups($company, $report, $pairs, $rule97, $balance);
            $planned = [];

            foreach ($groups as $stockNumber => $group) {
                $book = $balance((int) $stockNumber);
                $planned[] = [
                    'classes' => $group['classes'],
                    'stock_account' => (int) $stockNumber,
                    'change_account' => $group['change_account'],
                    'cost_value_ore' => $group['cost_value_ore'],
                    'value_ore' => $group['value_ore'],
                    'book_value_ore' => $book,
                    'change_ore' => $group['value_ore'] - $book,
                ];
            }

            $value = array_sum(array_column($planned, 'value_ore'));
            $book = array_sum(array_column($planned, 'book_value_ore'));

            return ['report' => $report, 'rule_97' => $rule97, 'value_ore' => $value, 'book_value_ore' => $book, 'change_ore' => $value - $book, 'groups' => $planned];
        });
    }

    /**
     * The value per stock account (ADR-073 §9): the report's lines summed per
     * stock class and the classes that share a stock account put together.
     * A class is included when it has stock, when it is the company's
     * default, when an article is given it, or when its stock account
     * carries a balance in the books for the year (then valued at what is
     * on hand, nothing if nothing is), so a class that is no longer used is
     * booked down instead of left standing beside the new one. A company
     * that never chose a class books one pair as before. With the 97 %
     * rule each class is taken up at 97 % of its cost, rounded half up.
     *
     * @param  array<string, array{0: int, 1: int}>  $pairs
     * @param  callable(int): int  $balance  the book balance of a stock account for the year
     * @return array<int, array{change_account: int, classes: list<string>, cost_value_ore: int, value_ore: int}>
     */
    private static function groups(Company $company, StockValuationReport $report, array $pairs, bool $rule97, callable $balance): array
    {
        $inUse = array_values(array_unique([
            InventorySettings::defaultClass($company)->value,
            ...array_column($report->lines, 'stock_class'),
            ...Article::query()->where('is_stock_item', true)->whereNotNull('stock_class')->distinct()->pluck('stock_class')->map(fn (StockClass $class): string => $class->value)->all(),
        ]));
        $groups = [];

        foreach (StockClass::cases() as $class) {
            [$stock, $change] = $pairs[$class->value];

            if (! in_array($class->value, $inUse, true) && $balance($stock) === 0) {
                continue;
            }

            $lines = array_values(array_filter($report->lines, fn (array $line): bool => $line['stock_class'] === $class->value));
            $cost = array_sum(array_column($lines, 'cost_value_ore'));
            $value = $rule97
                ? BigDecimal::of($cost)->multipliedBy(97)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt()
                : array_sum(array_column($lines, 'value_ore'));

            if (isset($groups[$stock]) && $groups[$stock]['change_account'] !== $change) {
                throw ValidationException::withMessages(['valuation' => __('Lagerkonto :account används för två lagerslag med olika konton för lagerförändring. Rätta kontona under Lager → Inställningar.', ['account' => $stock])]);
            }

            $groups[$stock] ??= ['change_account' => $change, 'classes' => [], 'cost_value_ore' => 0, 'value_ore' => 0];
            $groups[$stock]['classes'][] = $class->value;
            $groups[$stock]['cost_value_ore'] += $cost;
            $groups[$stock]['value_ore'] += $value;
        }

        return $groups;
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
