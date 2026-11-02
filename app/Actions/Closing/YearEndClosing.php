<?php

declare(strict_types=1);

namespace App\Actions\Closing;

use App\Actions\Accounting\BookVoucher;
use App\Actions\Accounting\CarryForwardBalances;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\CurrencyRevaluation;
use App\Domain\Closing\DividendLimit;
use App\Domain\Closing\UnpaidInvoices;
use App\Domain\Closing\YearResult;
use App\Domain\Company\AccountingMethod;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Models\Account;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\YearEndEntry;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The year-end closing (bokslut): ticking the checklist, booking the year's
 * tax and result on the last day of the year, closing the year, and in the
 * next year disposing of the result. Closing needs fiscal-years:manage and
 * periods:lock; everything else fiscal-years:manage.
 */
class YearEndClosing
{
    public const string SERIES = 'A';

    public function __construct(
        private readonly BookVoucher $book,
        private readonly CarryForwardBalances $carryForward,
    ) {}

    public function check(User $user, FiscalYear $fiscalYear, string $key, bool $checked): void
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);
        $this->ensureOpen($fiscalYear);

        if (! array_key_exists($key, ClosingChecklist::MANUAL) && ! array_key_exists($key, ClosingChecklist::CONFIRMABLE)) {
            throw ValidationException::withMessages(['key' => __('Okänd punkt i checklistan.')]);
        }

        CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $key, $checked): void {
            $checked
                ? ClosingCheck::query()->firstOrCreate(['fiscal_year_id' => $fiscalYear->id, 'key' => $key], ['checked_by' => $user->id])
                : ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->where('key', $key)->delete();

            Audit::record($checked ? 'closing.checked' : 'closing.unchecked', $fiscalYear, ['key' => $key], $company, $user);
        });
    }

    /**
     * Books the year's income tax: 8910 against 2512 on the last day. Zero
     * records that there is no tax, without a voucher.
     *
     * TODO(legal-verify): the tax itself is calculated by the user from the
     * taxable result (INK2), which differs from the book result.
     */
    public function bookTax(User $user, FiscalYear $fiscalYear, string $amount): YearEndEntry
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);
        $this->ensureOpen($fiscalYear);

        if (ClosingAccounts::isOwnerTaxed($company)) {
            throw ValidationException::withMessages(['tax' => __('Företagsformen betalar ingen egen inkomstskatt; ägarna beskattas.')]);
        }

        $tax = $this->money($amount, 'tax');

        if ($tax < 0) {
            throw ValidationException::withMessages(['tax' => __('Skatten kan inte vara negativ.')]);
        }

        return CurrentCompany::run($company, fn (): YearEndEntry => DB::transaction(function () use ($user, $company, $fiscalYear, $tax): YearEndEntry {
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::TAX)->exists()) {
                throw ValidationException::withMessages(['tax' => __('Årets skatt är redan bokförd. Rätta verifikationen om den blev fel.')]);
            }

            if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::RESULT)->exists()) {
                throw ValidationException::withMessages(['tax' => __('Årets resultat är redan bokat. Bokför skatten före resultatet.')]);
            }

            $voucher = null;

            if ($tax > 0) {
                ClosingAccounts::ensure(ClosingAccounts::TAX_EXPENSE);
                ClosingAccounts::ensure(ClosingAccounts::TAX_LIABILITY);
                $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, __('Årets skatt :year', ['year' => $fiscalYear->label()]), self::SERIES, [
                    ['account' => ClosingAccounts::TAX_EXPENSE, 'amount' => $tax],
                    ['account' => ClosingAccounts::TAX_LIABILITY, 'amount' => -$tax],
                ], VoucherSource::Closing, $fiscalYear);
            }

            $entry = YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => YearEndEntry::TAX, 'amount_ore' => $tax, 'voucher_id' => $voucher?->id, 'created_by' => $user->id]);
            Audit::record('closing.tax_booked', $fiscalYear, ['amount_ore' => $tax, 'voucher' => $voucher?->reference()], $company, $user);

            return $entry;
        }));
    }

    /**
     * Under kontantmetoden, books the customer and supplier invoices unpaid
     * on the last day of the year (UnpaidInvoices, BFL 5 kap. 2 §): one
     * voucher per side on the last day, 1510 against revenue and output VAT
     * and costs and input VAT against 2440, each reversed on the first day of
     * the next year, which must exist. Booked once, before the currency
     * revaluation and the result.
     *
     * @return list<YearEndEntry>
     */
    public function bookUnpaidInvoices(User $user, FiscalYear $fiscalYear): array
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);
        $this->ensureOpen($fiscalYear);

        if ($company->accounting_method !== AccountingMethod::Kontantmetoden) {
            throw ValidationException::withMessages(['unpaid' => __('Bara företag med kontantmetoden bokför obetalda fakturor vid årets slut.')]);
        }

        if ($fiscalYear->end_date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['unpaid' => __('De obetalda fakturorna bokförs efter årets sista dag, när det är klart vad som betalades i tid.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear): array {
            $next = FiscalYear::query()->whereDate('start_date', $fiscalYear->end_date->addDay()->toDateString())->first()
                ?? throw ValidationException::withMessages(['unpaid' => __('Lägg upp nästa räkenskapsår först. Bokföringen av de obetalda fakturorna återförs på dess första dag.')]);

            return DB::transaction(function () use ($user, $company, $fiscalYear, $next): array {
                FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
                $types = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->pluck('type')->all();

                if (array_intersect([YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES], $types) !== []) {
                    throw ValidationException::withMessages(['unpaid' => __('De obetalda fakturorna är redan bokförda. Rätta verifikationen om den blev fel.')]);
                }

                if (in_array(YearEndEntry::RESULT, $types, true)) {
                    throw ValidationException::withMessages(['unpaid' => __('Årets resultat är redan bokat. Bokför de obetalda fakturorna före resultatet.')]);
                }

                $unpaid = UnpaidInvoices::for($company, $fiscalYear);

                if (! $unpaid['applies']) {
                    throw ValidationException::withMessages(['unpaid' => __('Inga kund- eller leverantörsfakturor är obetalda den sista dagen.')]);
                }

                $entries = [];
                $audit = [];
                $sides = [
                    [YearEndEntry::UNPAID_RECEIVABLES, $unpaid['customer'], $unpaid['customerRows'], __('Obetalda kundfakturor vid årets slut :year', ['year' => $fiscalYear->label()]), __('Återföring av obetalda kundfakturor vid årets slut :year', ['year' => $fiscalYear->label()])],
                    [YearEndEntry::UNPAID_PAYABLES, $unpaid['supplier'], $unpaid['supplierRows'], __('Obetalda leverantörsfakturor vid årets slut :year', ['year' => $fiscalYear->label()]), __('Återföring av obetalda leverantörsfakturor vid årets slut :year', ['year' => $fiscalYear->label()])],
                ];

                foreach ($sides as [$type, $invoices, $rows, $description, $reversalDescription]) {
                    if ($rows === []) {
                        continue;
                    }

                    $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, $description, self::SERIES, $rows, VoucherSource::Closing, $fiscalYear);
                    $reversal = $this->book->rows($user, $company, $next->start_date, $reversalDescription, self::SERIES, array_map(fn (array $row): array => [...$row, 'amount' => -$row['amount']], $rows), VoucherSource::Closing, $fiscalYear);
                    $entries[] = YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => $type, 'amount_ore' => UnpaidInvoices::total($invoices), 'voucher_id' => $voucher->id, 'created_by' => $user->id]);
                    $audit[$type] = ['invoices' => count($invoices), 'amount_ore' => UnpaidInvoices::total($invoices), 'voucher' => $voucher->reference(), 'reversal' => $reversal->reference()];
                }

                Audit::record('closing.unpaid_invoices_booked', $fiscalYear, $audit, $company, $user);

                return $entries;
            });
        });
    }

    /**
     * Values the customer and supplier invoices in another currency that are
     * open on the last day at Riksbanken's rate that day (CurrencyRevaluation,
     * ADR-025): one voucher on the last day with the change of 1510 and 2440
     * against 3960 and 7960, and its reversal on the first day of the next
     * year, which must exist. Booked once, before the result.
     */
    public function bookCurrencyRevaluation(User $user, FiscalYear $fiscalYear): YearEndEntry
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);
        $this->ensureOpen($fiscalYear);

        if ($fiscalYear->end_date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['revaluation' => __('Omvärderingen görs efter årets sista dag, med kursen den dagen.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear): YearEndEntry {
            $unpaidBooked = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->whereIn('type', [YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES])->exists()
                || ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->where('key', 'unpaid')->exists();

            // Under kontantmetoden the invoices reach 1510 and 2440 only through the closing step for unpaid invoices (or by hand).
            if ($company->accounting_method === AccountingMethod::Kontantmetoden && ! $unpaidBooked) {
                throw ValidationException::withMessages(['revaluation' => __('Bokför först de obetalda fakturorna vid årets slut. Sedan kan de omvärderas.')]);
            }

            $revaluation = CurrencyRevaluation::for($company, $fiscalYear);

            if ($revaluation['missing'] !== []) {
                throw ValidationException::withMessages(['revaluation' => __('Riksbanken har ingen kurs för :currencies den :date. Bokför omvärderingen för hand.', ['currencies' => implode(', ', $revaluation['missing']), 'date' => $fiscalYear->end_date->toDateString()])]);
            }

            $rows = CurrencyRevaluation::rows($revaluation['lines']);

            if ($rows === []) {
                throw ValidationException::withMessages(['revaluation' => __('Inga fordringar eller skulder i utländsk valuta behöver omvärderas.')]);
            }

            $next = FiscalYear::query()->whereDate('start_date', $fiscalYear->end_date->addDay()->toDateString())->first()
                ?? throw ValidationException::withMessages(['revaluation' => __('Lägg upp nästa räkenskapsår först. Omvärderingen återförs på dess första dag.')]);

            return DB::transaction(function () use ($user, $company, $fiscalYear, $next, $revaluation, $rows): YearEndEntry {
                FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

                if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::CURRENCY_REVALUATION)->exists()) {
                    throw ValidationException::withMessages(['revaluation' => __('Omvärderingen är redan bokförd. Rätta verifikationen om den blev fel.')]);
                }

                if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::RESULT)->exists()) {
                    throw ValidationException::withMessages(['revaluation' => __('Årets resultat är redan bokat. Bokför omvärderingen före resultatet.')]);
                }

                foreach ($rows as $row) {
                    $this->ensureExchangeAccount($row['account'], $user, $company);
                }

                $rates = implode(', ', array_map(fn (string $currency): string => "{$currency} {$revaluation['rates'][$currency]->format()}", array_keys($revaluation['rates'])));
                $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, __('Omvärdering till balansdagens kurs :year (:rates)', ['year' => $fiscalYear->label(), 'rates' => $rates]), self::SERIES, $rows, VoucherSource::Closing, $fiscalYear);
                $reversal = $this->book->rows($user, $company, $next->start_date, __('Återföring av omvärdering till balansdagens kurs :year', ['year' => $fiscalYear->label()]), self::SERIES, array_map(fn (array $row): array => [...$row, 'amount' => -$row['amount']], $rows), VoucherSource::Closing, $fiscalYear);

                $net = CurrencyRevaluation::net($revaluation['lines']);
                $entry = YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => YearEndEntry::CURRENCY_REVALUATION, 'amount_ore' => $net, 'voucher_id' => $voucher->id, 'created_by' => $user->id]);
                Audit::record('closing.currency_revaluation_booked', $fiscalYear, [
                    'net_ore' => $net,
                    'rates' => array_map(fn ($rate): array => ['micros' => $rate->micros, 'date' => $rate->date, 'source' => $rate->source], $revaluation['rates']),
                    'invoices' => count($revaluation['lines']),
                    'voucher' => $voucher->reference(),
                    'reversal' => $reversal->reference(),
                ], $company, $user);

                return $entry;
            });
        });
    }

    /**
     * Moves what is left of the year's result to equity: 8999 against 2099
     * (2019 for sole traders and partnerships) on the last day. Booked again
     * for the difference if something was posted afterwards.
     */
    public function bookResult(User $user, FiscalYear $fiscalYear): YearEndEntry
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);
        $this->ensureOpen($fiscalYear);

        return CurrentCompany::run($company, fn (): YearEndEntry => DB::transaction(function () use ($user, $company, $fiscalYear): YearEndEntry {
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            if (! ClosingAccounts::isOwnerTaxed($company) && ! YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::TAX)->exists()) {
                throw ValidationException::withMessages(['result' => __('Bokför årets skatt först, eller noll om det inte blir någon skatt.')]);
            }

            $unbooked = YearResult::of($fiscalYear)->unbookedOre();

            if ($unbooked === 0) {
                throw ValidationException::withMessages(['result' => __('Årets resultat är redan bokat.')]);
            }

            ClosingAccounts::ensure(ClosingAccounts::YEAR_RESULT);
            $equity = ClosingAccounts::ensure(ClosingAccounts::resultNumber($company));
            $voucher = $this->book->rows($user, $company, $fiscalYear->end_date, __('Årets resultat :year', ['year' => $fiscalYear->label()]), self::SERIES, [
                ['account' => ClosingAccounts::YEAR_RESULT, 'amount' => -$unbooked],
                ['account' => $equity->number, 'amount' => $unbooked],
            ], VoucherSource::Closing, $fiscalYear);

            $entry = YearEndEntry::create(['fiscal_year_id' => $fiscalYear->id, 'type' => YearEndEntry::RESULT, 'amount_ore' => -$unbooked, 'voucher_id' => $voucher->id, 'created_by' => $user->id]);
            Audit::record('closing.result_booked', $fiscalYear, ['result_ore' => -$unbooked, 'voucher' => $voucher->reference()], $company, $user);

            return $entry;
        }));
    }

    /**
     * Closes the year for good: the checklist must be complete. The year
     * becomes CLOSED, the bookkeeping is locked through its last day, and
     * the closing balances are carried to the next year.
     */
    public function close(User $user, FiscalYear $fiscalYear): FiscalYear
    {
        $company = $this->companyOf($fiscalYear);
        $this->authorize($user, $company);

        if (! $user->hasTeamPermission($company, 'periods:lock')) {
            throw new AuthorizationException;
        }

        $this->ensureOpen($fiscalYear);

        return CurrentCompany::run($company, fn (): FiscalYear => DB::transaction(function () use ($user, $company, $fiscalYear): FiscalYear {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
            $earlierOpen = FiscalYear::query()->where('status', FiscalYearStatus::Open->value)->whereDate('end_date', '<', $fiscalYear->start_date->toDateString())->exists();

            if ($earlierOpen) {
                throw ValidationException::withMessages(['close' => __('Stäng tidigare räkenskapsår först.')]);
            }

            $items = ClosingChecklist::for($company, $fiscalYear);

            if (! ClosingChecklist::isComplete($items)) {
                throw ValidationException::withMessages(['close' => __('Checklistan är inte klar: :items.', ['items' => implode(', ', array_column(array_filter($items, fn (array $item): bool => $item['status'] === ClosingChecklist::TODO), 'label'))])]);
            }

            // A year with nothing in its books has nothing to carry forward.
            $hasBooks = LedgerBalances::opening($fiscalYear)->isNotEmpty()
                || Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Posted->value)->exists();
            $next = $hasBooks ? ($this->carryForward)($user, $fiscalYear) : null;

            $fiscalYear->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now(), 'closed_by' => $user->id])->save();

            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->locked_until_date === null || $locked->locked_until_date->lessThan($fiscalYear->end_date)) {
                $locked->forceFill(['locked_until_date' => $fiscalYear->end_date->toDateString()])->save();
            }

            Audit::record('fiscal_year.closed', $fiscalYear, ['label' => $fiscalYear->label(), 'next' => $next?->label()], $company, $user);

            return $fiscalYear;
        }));
    }

    /**
     * Disposes of a closed year's result in the next year (resultatdisposition),
     * after the annual meeting: the result moves from 2099 to 2098, less the
     * dividend on 2898. For sole traders and partnerships it moves from 2019
     * to 2010.
     *
     * The dividend may not exceed the free equity of the closed year's
     * balance sheet (ABL 17 kap. 3 §, DividendLimit; #85). The prudence rule
     * is left to the meeting.
     */
    public function dispose(User $user, FiscalYear $closedYear, string $date, string $dividend): YearEndEntry
    {
        $company = $this->companyOf($closedYear);
        $this->authorize($user, $company);
        $closedYear = $closedYear->fresh() ?? $closedYear;

        if ($closedYear->isOpen()) {
            throw ValidationException::withMessages(['disposition' => __('Stäng räkenskapsåret innan resultatet disponeras.')]);
        }

        $dividendOre = ClosingAccounts::isOwnerTaxed($company) ? 0 : $this->money($dividend === '' ? '0' : $dividend, 'dividend');

        if ($dividendOre < 0) {
            throw ValidationException::withMessages(['dividend' => __('Utdelningen kan inte vara negativ.')]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['date' => __('Välj datum.')]);
        }

        return CurrentCompany::run($company, fn (): YearEndEntry => DB::transaction(function () use ($user, $company, $closedYear, $date, $dividendOre): YearEndEntry {
            if (YearEndEntry::query()->where('fiscal_year_id', $closedYear->id)->where('type', YearEndEntry::DISPOSITION)->exists()) {
                throw ValidationException::withMessages(['disposition' => __('Resultatet för :year är redan disponerat.', ['year' => $closedYear->label()])]);
            }

            $next = FiscalYear::query()->whereDate('start_date', $closedYear->end_date->addDay()->toDateString())->firstOrFail();
            $day = CarbonImmutable::parse($date);

            if (! $next->contains($day)) {
                throw ValidationException::withMessages(['date' => __('Datumet ska ligga i räkenskapsåret :year.', ['year' => $next->label()])]);
            }

            $resultAccount = ClosingAccounts::ensure(ClosingAccounts::resultNumber($company));
            $result = (int) (LedgerBalances::opening($next)[$resultAccount->id] ?? 0);

            if ($result === 0) {
                throw ValidationException::withMessages(['disposition' => __('Det finns inget resultat att disponera på konto :account.', ['account' => $resultAccount->number])]);
            }

            if ($dividendOre > 0 && $result > 0) {
                throw ValidationException::withMessages(['dividend' => __('Året gick med förlust. Utdelning beslutas inte ur årets resultat här.')]);
            }

            DividendLimit::ensureWithin($company, $closedYear, $dividendOre);

            $lines = ClosingAccounts::isOwnerTaxed($company)
                ? [
                    ['account' => $resultAccount->number, 'amount' => -$result],
                    ['account' => ClosingAccounts::ensure(ClosingAccounts::OWNER_EQUITY)->number, 'amount' => $result],
                ]
                : [
                    ['account' => $resultAccount->number, 'amount' => -$result],
                    ['account' => ClosingAccounts::ensure(ClosingAccounts::RETAINED)->number, 'amount' => $result + $dividendOre],
                    ['account' => ClosingAccounts::ensure(ClosingAccounts::DIVIDEND)->number, 'amount' => -$dividendOre],
                ];

            $voucher = $this->book->rows($user, $company, $day, __('Resultatdisposition :year', ['year' => $closedYear->label()]), self::SERIES, $lines, VoucherSource::Closing, $closedYear);
            $entry = YearEndEntry::create(['fiscal_year_id' => $closedYear->id, 'type' => YearEndEntry::DISPOSITION, 'amount_ore' => -$result, 'dividend_ore' => $dividendOre, 'voucher_id' => $voucher->id, 'created_by' => $user->id]);
            Audit::record('closing.result_disposed', $closedYear, ['result_ore' => -$result, 'dividend_ore' => $dividendOre, 'voucher' => $voucher->reference()], $company, $user);

            return $entry;
        }));
    }

    private function ensureOpen(FiscalYear $fiscalYear): void
    {
        if (! ($fiscalYear->fresh() ?? $fiscalYear)->isOpen()) {
            throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :year är stängt.', ['year' => $fiscalYear->label()])]);
        }
    }

    /** Adds 3960 or 7960 from BAS when the chart lacks it, as for invoices (ADR-025). */
    private function ensureExchangeAccount(int $number, User $user, Company $company): void
    {
        $name = SupplierInvoiceBooking::EXCHANGE_ACCOUNT_NAMES[$number] ?? null;

        if ($name === null || Account::query()->where('number', $number)->exists()) {
            return;
        }

        $account = Account::create(['number' => $number, 'name' => $name, 'type' => AccountType::forNumber($number)]);
        Audit::record('account.created', $account, ['number' => $number, 'name' => $name, 'reason' => 'exchange_difference'], $company, $user);
    }

    private function companyOf(FiscalYear $fiscalYear): Company
    {
        return Company::query()->findOrFail($fiscalYear->company_id);
    }

    private function money(string $value, string $field): int
    {
        try {
            return Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }
    }
}
