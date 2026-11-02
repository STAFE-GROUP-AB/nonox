<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\VoucherRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * @param  int  $unexplained  öre of taxable base that output VAT on sales implies but no revenue row carries (#61)
     */
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $exact,
        public readonly array $sources,
        public readonly array $vatAccounts,
        public readonly array $unsettled = [],
        public readonly int $unexplained = 0,
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
            $sources[$box][$account->number] = ['number' => $account->number, 'name' => $account->name, 'amount' => $amount];
        }

        $unexplained = 0;

        foreach (self::salesByVoucher($fiscalYear->company_id, $start, $end) as $rows) {
            $unexplained += self::placeSales($rows, $exact, $sources);
        }

        ksort($vatAccounts);
        usort($unsettled, fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return new self($start, $end, $exact, self::listSources($sources), $vatAccounts, $unsettled, $unexplained);
    }

    /**
     * Places one voucher's sales that the account alone does not settle
     * (#61): a credit on a revenue account without a box, such as 3990,
     * counts in box 05 as far as the voucher's output VAT on sales in Sweden
     * (SALE_VAT) explains it, after the sales already on box 05 accounts.
     * Returns the base, in öre, that the output VAT implies but no revenue
     * row on the voucher carries, beyond what rounding the VAT to whole
     * kronor can explain.
     *
     * TODO(legal-verify): the base is derived as VAT / rate, so it is only as
     * exact as the VAT was rounded; a whole-krona difference per voucher is
     * accepted as rounding.
     *
     * @param  list<array{number: int, name: string, net: int}>  $rows  credit minus debit per account on the voucher
     * @param  array<int, int>  $exact
     * @param  array<int, array<int, array{number: int, name: string, amount: int}>>  $sources
     */
    private static function placeSales(array $rows, array &$exact, array &$sources): int
    {
        $base = BigDecimal::zero();
        $tolerance = BigDecimal::zero();
        $taxable = 0;
        $other = [];

        foreach ($rows as $row) {
            if (isset(VatBoxes::SALE_VAT[$row['number']])) {
                $rate = VatBoxes::SALE_VAT[$row['number']];
                $base = $base->plus(BigDecimal::of($row['net'])->multipliedBy(10_000)->dividedBy($rate, 4, RoundingMode::HalfUp));
                $tolerance = $tolerance->plus(BigDecimal::of(50 * 10_000)->dividedBy($rate, 4, RoundingMode::HalfUp));
            } elseif (VatBoxes::boxFor($row['number']) === VatBoxes::TAXABLE_SALES) {
                $taxable += $row['net'];
            } elseif (VatBoxes::isOtherRevenue($row['number']) && $row['net'] !== 0) {
                $other[] = $row;
            }
        }

        $implied = $base->toScale(0, RoundingMode::HalfUp)->toInt();

        if ($implied === 0) {
            return 0;
        }

        // Work in the direction of the sale, so a credit invoice reads like an invoice.
        $sign = $implied > 0 ? 1 : -1;
        $open = max(abs($implied) - max($sign * $taxable, 0), 0);

        foreach ($other as $row) {
            $share = min(max($sign * $row['net'], 0), $open);

            if ($share === 0) {
                continue;
            }

            $open -= $share;
            self::addSource($exact, $sources, VatBoxes::TAXABLE_SALES, $row['number'], $row['name'], $sign * $share);
        }

        return $open > $tolerance->toScale(0, RoundingMode::Ceiling)->toInt() ? $sign * $open : 0;
    }

    /**
     * @param  array<int, int>  $exact
     * @param  array<int, array<int, array{number: int, name: string, amount: int}>>  $sources
     */
    private static function addSource(array &$exact, array &$sources, int $box, int $number, string $name, int $amount): void
    {
        $exact[$box] += $amount;
        $current = $sources[$box][$number]['amount'] ?? 0;
        $sources[$box][$number] = ['number' => $number, 'name' => $name, 'amount' => $current + $amount];
    }

    /**
     * @param  array<int, array<int, array{number: int, name: string, amount: int}>>  $sources
     * @return array<int, list<array{number: int, name: string, amount: int}>>
     */
    private static function listSources(array $sources): array
    {
        $result = [];

        foreach ($sources as $box => $accounts) {
            ksort($accounts);
            $listed = array_values(array_filter($accounts, fn (array $source): bool => $source['amount'] !== 0));

            if ($listed !== []) {
                $result[$box] = $listed;
            }
        }

        return $result;
    }

    /**
     * Per posted voucher of the period, credit minus debit on its revenue
     * accounts (3000–3999) and output VAT accounts (2610–2639): what box 05
     * and box 42 need beyond the account of each row. One query.
     *
     * @return Collection<array-key, list<array{number: int, name: string, net: int}>>
     */
    private static function salesByVoucher(int $companyId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.company_id', $companyId)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('vouchers.source', '<>', VoucherSource::Vat->value)
            ->whereBetween('vouchers.voucher_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn (Builder $query) => $query->whereBetween('accounts.number', [2610, 2639])->orWhereBetween('accounts.number', [3000, 3999]))
            ->groupBy('voucher_rows.voucher_id', 'accounts.number', 'accounts.name')
            ->orderBy('voucher_rows.voucher_id')
            ->orderBy('accounts.number')
            ->selectRaw('voucher_rows.voucher_id, accounts.number, accounts.name, SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) AS net')
            ->get()
            ->groupBy(fn (VoucherRow $row): string => (string) $row->voucher_id)
            ->map(fn (Collection $rows): array => array_values($rows->map(fn (VoucherRow $row): array => [
                'number' => (int) $row->getAttribute('number'),
                'name' => (string) $row->getAttribute('name'),
                'net' => (int) $row->getAttribute('net'),
            ])->all()));
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

    /**
     * Whether a VAT account that feeds a box has moved since the period was
     * booked: then a supplementary settlement is needed (#63).
     *
     * @param  array<int, int>  $booked  VatReport::bookedRows()
     */
    public function needsSupplement(array $booked): bool
    {
        $rows = $this->settlementRows($booked);

        return array_filter($rows, fn (int $amount, int $number): bool => VatBoxes::isSettled($number) && $amount !== 0, ARRAY_FILTER_USE_BOTH) !== [];
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
     * With $booked, what earlier settlement vouchers of the period booked,
     * the rows are only the difference: a supplementary settlement after a
     * late posting (#63). Together the vouchers then book the period as one.
     *
     * @param  array<int, int>  $booked  account number => signed öre, VatReport::bookedRows()
     * @return array<int, int> account number => amount
     */
    public function settlementRows(array $booked = []): array
    {
        $rows = [];

        foreach ($this->vatAccounts as $number => $change) {
            $rows[$number] = -$change;
        }

        foreach ($booked as $number => $amount) {
            if (VatBoxes::isSettled($number)) {
                $rows[$number] = ($rows[$number] ?? 0) - $amount;
            }
        }

        $settlement = -$this->payable() * 100 - ($booked[VatBoxes::SETTLEMENT_ACCOUNT] ?? 0);
        $rounding = -(array_sum($rows) + $settlement);

        $rows[VatBoxes::SETTLEMENT_ACCOUNT] = $settlement;

        if ($rounding !== 0) {
            $rows[VatBoxes::ROUNDING_ACCOUNT] = $rounding;
        }

        return array_filter($rows);
    }
}
