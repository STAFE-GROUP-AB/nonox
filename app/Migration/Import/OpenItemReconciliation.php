<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Company\AccountingMethod;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;

/**
 * The gate for open invoices (ADR-076, section 3): the sum of what is still
 * unpaid in the file must equal the ledger balance on 1510 (customers) or
 * 2440 (suppliers) from the SIE import. The invoices are created without a
 * voucher, so the opening balance in the ledger is what they explain. Any
 * difference blocks the import; there is no override.
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
     * The ledger balance the invoices must add up to, in öre, as a positive
     * amount (what customers owe, or what is owed to suppliers); null when
     * there is no such account or year.
     */
    public static function ledgerAmount(RegisterKind $kind, ?int $fiscalYearId): ?int
    {
        $account = Account::query()->where('number', $kind->controlAccount())->first();
        $year = $fiscalYearId === null ? FiscalYear::query()->latest('start_date')->first() : FiscalYear::query()->find($fiscalYearId);

        if ($account === null || $year === null) {
            return null;
        }

        $balance = (int) (LedgerBalances::opening($year)->get($account->id, 0) + LedgerBalances::movements($year)->get($account->id, 0));

        return $kind === RegisterKind::OpenCustomerInvoices ? $balance : -$balance;
    }

    /**
     * @return array{file_ore: int, ledger_ore: ?int, difference_ore: ?int, equal: bool}
     */
    public static function compare(RegisterKind $kind, int $fileOre, ?int $fiscalYearId): array
    {
        $ledger = self::ledgerAmount($kind, $fiscalYearId);

        return [
            'file_ore' => $fileOre,
            'ledger_ore' => $ledger,
            'difference_ore' => $ledger === null ? null : $fileOre - $ledger,
            'equal' => $ledger !== null && $ledger === $fileOre,
        ];
    }
}
