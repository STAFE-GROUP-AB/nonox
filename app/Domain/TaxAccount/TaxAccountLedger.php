<?php

declare(strict_types=1);

namespace App\Domain\TaxAccount;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\TaxAccountPosting;
use App\Models\Voucher;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Account 1630 in the books of the current company (ADR-054 stage 3): its
 * balance on a day and the posted vouchers that move it. A fixed number of
 * queries, whatever the number of vouchers.
 */
final class TaxAccountLedger
{
    /** The balance of 1630 at the end of the day in öre, debit positive; null without a fiscal year for the day. */
    public static function balance(CarbonImmutable $date): ?int
    {
        $account = self::account();
        $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())->first();

        if ($fiscalYear === null) {
            return null;
        }

        if ($account === null) {
            return 0;
        }

        $movement = (int) VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('voucher_rows.account_id', $account->id)
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));

        return (int) (LedgerBalances::opening($fiscalYear)[$account->id] ?? 0) + $movement;
    }

    /**
     * Each posted voucher's net movement on 1630 between two days, and the
     * voucher that corrected it, if any.
     *
     * @return list<array{voucher_id: string, reference: string, date: string, amount_ore: int, corrected_by: ?string}>
     */
    public static function entries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = self::account();

        if ($account === null) {
            return [];
        }

        return array_values(VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('voucher_rows.account_id', $account->id)
            ->whereDate('vouchers.voucher_date', '>=', $from->toDateString())
            ->whereDate('vouchers.voucher_date', '<=', $to->toDateString())
            ->groupBy('vouchers.id', 'vouchers.voucher_date', 'voucher_series.code', 'vouchers.number', 'vouchers.corrected_by_voucher_id')
            ->orderBy('vouchers.voucher_date')->orderBy('voucher_series.code')->orderBy('vouchers.number')
            ->selectRaw('vouchers.id as voucher_id, vouchers.voucher_date as voucher_date, voucher_series.code as series_code, vouchers.number as voucher_number, vouchers.corrected_by_voucher_id as corrected_by, sum(voucher_rows.debit_ore - voucher_rows.credit_ore) as amount')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'voucher_id' => (string) $row->voucher_id,
                'reference' => $row->series_code.$row->voucher_number,
                'date' => CarbonImmutable::parse((string) $row->voucher_date)->toDateString(),
                'amount_ore' => (int) $row->amount,
                'corrected_by' => $row->corrected_by === null ? null : (string) $row->corrected_by,
            ])
            ->filter(fn (array $entry): bool => $entry['amount_ore'] !== 0)
            ->all());
    }

    /**
     * The transactions NONOX has booked from the tax account and that are in
     * force, by Skatteverket's id; "corrected" when the voucher has since
     * been corrected, so the transaction may be booked again.
     *
     * @param  list<int>  $transactionIds
     * @return array<int, array{voucher_id: ?string, reference: string, corrected: bool}>
     */
    public static function booked(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $postings = TaxAccountPosting::query()->whereIn('transaction_id', $transactionIds)->whereNull('superseded_at')->get(['id', 'transaction_id']);

        // vouchers.source_id is a string column, so the ids are compared as strings.
        $vouchers = $postings->isEmpty() ? collect() : Voucher::query()
            ->where('source_type', (new TaxAccountPosting)->getMorphClass())
            ->whereIn('source_id', $postings->map(fn (TaxAccountPosting $posting): string => (string) $posting->id)->all())
            ->with('series')
            ->get()
            ->keyBy(fn (Voucher $voucher): string => (string) $voucher->source_id);
        $booked = [];

        foreach ($postings as $posting) {
            $voucher = $vouchers->get((string) $posting->id);
            $booked[$posting->transaction_id] = [
                'voucher_id' => $voucher?->id,
                'reference' => $voucher?->reference() ?? '',
                'corrected' => $voucher?->corrected_by_voucher_id !== null,
            ];
        }

        return $booked;
    }

    /**
     * The balances of some accounts at the end of a day in öre, debit
     * positive, by account number; an account without a balance is 0.
     *
     * @param  list<int>  $numbers
     * @return array<int, int>
     */
    public static function balances(array $numbers, CarbonImmutable $date): array
    {
        $accounts = Account::query()->whereIn('number', $numbers)->pluck('number', 'id');
        $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())->first();
        $balances = array_fill_keys($numbers, 0);

        if ($fiscalYear === null || $accounts->isEmpty()) {
            return $balances;
        }

        $opening = OpeningBalance::query()->where('fiscal_year_id', $fiscalYear->id)->whereIn('account_id', $accounts->keys())->get(['account_id', 'amount_ore']);
        $movements = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('voucher_rows.account_id', $accounts->keys())
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id as account_id, sum(voucher_rows.debit_ore - voucher_rows.credit_ore) as amount')
            ->toBase()
            ->get();

        foreach ($opening as $row) {
            $balances[(int) $accounts[$row->account_id]] += $row->amount_ore->ore;
        }

        foreach ($movements as $row) {
            $balances[(int) $accounts[$row->account_id]] += (int) $row->amount;
        }

        return $balances;
    }

    public static function account(): ?Account
    {
        return Account::query()->where('number', TaxAccountProposals::TAX_ACCOUNT)->first();
    }
}
