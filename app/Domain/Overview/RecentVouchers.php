<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Voucher;

/**
 * The latest posted vouchers for Översikt, with an amount signed from the
 * company's point of view (ADR-027):
 *
 * - A voucher that touches the bank and cash accounts (1900–1999) shows
 *   their net change: debit minus credit. Money in is positive (+), money
 *   out is negative (−).
 * - Any other voucher (an invoice booked on 1510, a depreciation) moves no
 *   money; it shows its total (the sum of the debits) without a sign.
 *
 * One query: the five newest vouchers are picked first, then only their
 * rows are summed.
 */
final class RecentVouchers
{
    public const int LIMIT = 5;

    private const array CASH = [1900, 1999];

    /**
     * @return list<array{id: string, series: string, number: ?int, description: string, date: string, amount: int, signed: bool}>
     */
    public function latest(int $limit = self::LIMIT): array
    {
        $latest = Voucher::query()
            ->select('id')
            ->where('status', VoucherStatus::Posted->value)
            ->orderByDesc('posted_at')
            ->orderByDesc('voucher_date')
            ->orderByDesc('number')
            ->limit($limit);

        return array_values(Voucher::query()
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->join('voucher_rows', 'voucher_rows.voucher_id', '=', 'vouchers.id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->whereIn('vouchers.id', $latest)
            ->groupBy('vouchers.id', 'voucher_series.code')
            ->orderByDesc('vouchers.posted_at')
            ->orderByDesc('vouchers.voucher_date')
            ->orderByDesc('vouchers.number')
            ->toBase()
            ->select('vouchers.id', 'vouchers.number', 'vouchers.description', 'vouchers.voucher_date', 'vouchers.posted_at', 'voucher_series.code')
            ->selectRaw('SUM(voucher_rows.debit_ore) AS total')
            ->selectRaw('SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?) AS cash', self::CASH)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'series' => (string) $row->code,
                'number' => $row->number === null ? null : (int) $row->number,
                'description' => (string) $row->description,
                'date' => substr((string) $row->voucher_date, 0, 10),
                'amount' => $row->cash === null ? (int) $row->total : (int) $row->cash,
                'signed' => $row->cash !== null,
            ])
            ->all());
    }
}
