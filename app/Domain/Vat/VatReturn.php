<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Models\Account;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

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

    public static function calculate(FiscalYear $fiscalYear, CarbonImmutable $start, CarbonImmutable $end): self
    {
        $query = new LedgerQuery($fiscalYear, $start, $end, excludeVatSettlements: true);
        $movements = $query->movements();
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
