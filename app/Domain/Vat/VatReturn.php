<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The momsdeklaration for one period, calculated from the posted ledger
 * (AGENTS.md §8.2). Earlier VAT settlements are left out, so the report of
 * a period reads the same before and after its VAT is booked.
 *
 * Boxes are reported in whole kronor, with the öre dropped.
 * TODO(legal-verify): confirm that öre are dropped (not rounded) per box, and
 * that box 49 is the difference of the rounded boxes.
 */
final class VatReturn
{
    /**
     * @param  array<int, int>  $exact  box => öre
     * @param  array<int, list<array{number: int, name: string, amount: int}>>  $sources  box => contributing accounts
     * @param  array<int, int>  $vatAccounts  account number => debit minus credit on the VAT accounts that feed a VAT box
     * @param  list<array{number: int, name: string, amount: int}>  $unsettled  VAT accounts (2610–2649) with a movement but no box, debit minus credit
     */
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $exact,
        public readonly array $sources,
        public readonly array $vatAccounts,
        public readonly array $unsettled = [],
    ) {}

    /**
     * The fiscal year only names the company: the period is read by date
     * across fiscal years, because a calendar quarter can straddle a broken
     * fiscal year and is still one declaration (SFL 26 kap. 11 §, #59).
     */
    public static function calculate(FiscalYear $fiscalYear, CarbonImmutable $start, CarbonImmutable $end): self
    {
        $movements = self::movements($fiscalYear->company_id, $start, $end);
        $accounts = Account::query()->whereIn('id', $movements->keys())->get()->keyBy('id');

        $exact = array_fill_keys(array_keys(VatBoxes::BOXES), 0);
        $sources = [];
        $vatAccounts = [];
        $unsettled = [];

        foreach ($movements as $accountId => $movement) {
            $account = $accounts->get($accountId);

            if (! $account instanceof Account) {
                continue;
            }

            $change = $movement['debit'] - $movement['credit'];

            // OSS VAT is declared in the OSS return, so it is neither in a box nor settled on 2650.
            if ($account->number === OssRates::VAT_ACCOUNT) {
                continue;
            }

            // Only VAT that is declared is settled; vilande moms (2618, 2628, 2638, 2648) and other VAT accounts without a box stay put (#58).
            if (VatBoxes::isSettled($account->number) && $change !== 0) {
                $vatAccounts[$account->number] = $change;
            } elseif (VatBoxes::isVatAccount($account->number) && $change !== 0) {
                $unsettled[] = ['number' => $account->number, 'name' => $account->name, 'amount' => $change];
            }

            $box = VatBoxes::boxFor($account->number);

            if ($box === null) {
                continue;
            }

            $amount = VatBoxes::BOXES[$box]['side'] === VatBoxes::CREDIT ? -$change : $change;

            if ($amount === 0) {
                continue;
            }

            $exact[$box] += $amount;
            $sources[$box][] = ['number' => $account->number, 'name' => $account->name, 'amount' => $amount];
        }

        ksort($vatAccounts);
        usort($unsettled, fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return new self($start, $end, $exact, $sources, $vatAccounts, $unsettled);
    }

    /**
     * Debit and credit per account id over the period, from the company's
     * posted vouchers in any fiscal year, VAT settlements left out. Opening
     * balances are not vouchers, so nothing is counted twice at a year end.
     *
     * @return Collection<int, array{debit: int, credit: int}>
     */
    private static function movements(int $companyId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.company_id', $companyId)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('vouchers.source', '<>', VoucherSource::Vat->value)
            ->whereBetween('vouchers.voucher_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore) AS debit, SUM(voucher_rows.credit_ore) AS credit')
            ->get()
            ->mapWithKeys(fn (VoucherRow $row): array => [(int) $row->account_id => [
                'debit' => (int) $row->getAttribute('debit'),
                'credit' => (int) $row->getAttribute('credit'),
            ]]);
    }

    /**
     * Every box in whole kronor, box 49 included.
     *
     * @return array<int, int>
     */
    public function boxes(): array
    {
        $boxes = array_map(fn (int $ore): int => intdiv($ore, 100), $this->exact);
        $boxes[VatBoxes::PAYABLE] = array_sum(array_intersect_key($boxes, array_flip(VatBoxes::OUTPUT_VAT))) - ($boxes[VatBoxes::INPUT_VAT] ?? 0);

        return $boxes;
    }

    public function payable(): int
    {
        return $this->boxes()[VatBoxes::PAYABLE];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->exact) === [] && $this->vatAccounts === [];
    }

    /**
     * The settlement voucher's rows, signed öre (debit positive): every VAT
     * account that feeds a box back to zero for the period, the total to 2650
     * in whole kronor, and the öre dropped from the declared boxes to 3740.
     * Accounts without a box are not touched, so 3740 only ever holds the
     * rounding of the boxes: less than one krona per box.
     *
     * @return array<int, int> account number => amount
     */
    public function settlementRows(): array
    {
        $rows = [];

        foreach ($this->vatAccounts as $number => $change) {
            $rows[$number] = -$change;
        }

        $settlement = -$this->payable() * 100;
        $rounding = -(array_sum($rows) + $settlement);

        $rows[VatBoxes::SETTLEMENT_ACCOUNT] = ($rows[VatBoxes::SETTLEMENT_ACCOUNT] ?? 0) + $settlement;

        if ($rounding !== 0) {
            $rows[VatBoxes::ROUNDING_ACCOUNT] = $rounding;
        }

        return array_filter($rows);
    }
}
