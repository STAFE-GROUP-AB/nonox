<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

/**
 * The gate for open invoices (ADR-076, section 3): what the file says is still
 * unpaid, plus what is open on invoices made in NONOX, must equal the ledger
 * balance on 1510 (customers) or 2440 (suppliers) from the SIE import, plus
 * any sub-accounts the user chose (for example 1511). The invoices are created
 * without a voucher, so the opening balance in the ledger is what they
 * explain. Credit notes count with a minus. Any difference blocks the import;
 * there is no override. The check is on the total only, not per customer or
 * supplier.
 */
final class OpenItemReconciliation
{
    /**
     * Problems that stop the import before any sum is compared.
     *
     * @return list<string>
     */
    public static function preconditions(Company $company, RegisterKind $kind): array
    {
        $blockers = [];

        if ($company->accounting_method !== AccountingMethod::Fakturametoden) {
            $blockers[] = __('Obetalda fakturor kan bara läsas in för företag som redovisar enligt fakturametoden. För kontantmetoden väntar vi på besked från en redovisningskonsult om hur de ska bokföras.');
        }

        if (FiscalYear::query()->doesntExist()) {
            $blockers[] = __('Läs in bokföringen (SIE-filen) först. Fakturorna förklarar saldot på :account och det saldot kommer från SIE-filen.', ['account' => $kind->controlAccount()]);
        }

        return $blockers;
    }

    /**
     * The fiscal year to compare against when the user has not chosen: the
     * latest one that has posted vouchers, since an empty later year says
     * nothing about the balance.
     */
    public static function defaultYear(): ?FiscalYear
    {
        foreach (FiscalYear::query()->orderByDesc('start_date')->get() as $year) {
            if (DB::table('vouchers')->where('fiscal_year_id', $year->id)->where('status', 'POSTED')->exists()) {
                return $year;
            }
        }

        return FiscalYear::query()->latest('start_date')->first();
    }

    /**
     * The ledger balance the invoices must add up to, in öre, as a positive
     * amount (what customers owe, or what is owed to suppliers); null when
     * there is no such account or year.
     *
     * @param  array<int>  $extraAccounts  sub-accounts that belong to the same receivable or debt
     */
    public static function ledgerAmount(RegisterKind $kind, ?int $fiscalYearId, array $extraAccounts = []): ?int
    {
        $numbers = array_values(array_unique([(int) $kind->controlAccount(), ...$extraAccounts]));
        $accounts = Account::query()->whereIn('number', $numbers)->pluck('id', 'number');
        $year = $fiscalYearId === null ? self::defaultYear() : FiscalYear::query()->find($fiscalYearId);

        if (! $accounts->has((int) $kind->controlAccount()) || $year === null) {
            return null;
        }

        $opening = LedgerBalances::opening($year);
        $movements = LedgerBalances::movements($year);
        $balance = 0;

        foreach ($accounts as $id) {
            $balance += (int) ($opening->get($id, 0) + $movements->get($id, 0));
        }

        return $kind === RegisterKind::OpenCustomerInvoices ? $balance : -$balance;
    }

    /** What is open on invoices made in NONOX, not imported ones, credit notes with a minus. */
    public static function openInNonox(RegisterKind $kind): int
    {
        if ($kind === RegisterKind::OpenCustomerInvoices) {
            return (int) Invoice::query()
                ->whereNull('import_source')
                ->where('status', InvoiceStatus::Issued->value)
                ->sum(DB::raw('total_ore - paid_ore'));
        }

        return (int) SupplierInvoice::query()
            ->whereNull('import_source')
            ->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])
            ->sum(DB::raw('total_ore - paid_ore'));
    }

    /**
     * @param  array<int>  $extraAccounts
     * @return array{file_ore: int, nonox_ore: int, expected_ore: int, ledger_ore: ?int, difference_ore: ?int, equal: bool}
     */
    public static function compare(RegisterKind $kind, int $fileOre, ?int $fiscalYearId, array $extraAccounts = []): array
    {
        $ledger = self::ledgerAmount($kind, $fiscalYearId, $extraAccounts);
        $nonox = self::openInNonox($kind);
        $expected = $fileOre + $nonox;

        return [
            'file_ore' => $fileOre,
            'nonox_ore' => $nonox,
            'expected_ore' => $expected,
            'ledger_ore' => $ledger,
            'difference_ore' => $ledger === null ? null : $expected - $ledger,
            'equal' => $ledger !== null && $ledger === $expected,
        ];
    }
}
