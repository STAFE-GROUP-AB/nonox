<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Support\Money\Money;

/**
 * Konteringsmallar (AGENTS.md §11.2): common vouchers built from one amount.
 * The amount is what was paid or received, including VAT. Purchase templates
 * leave the expense account empty for the user to choose.
 */
enum VoucherTemplate: string
{
    case Purchase25 = 'inkop-25';
    case Purchase12 = 'inkop-12';
    case Purchase6 = 'inkop-6';
    case PurchaseNoVat = 'inkop-utan-moms';
    case Sale25 = 'forsaljning-25';
    case Sale12 = 'forsaljning-12';
    case Sale6 = 'forsaljning-6';
    case BankFee = 'bankavgift';

    public function label(): string
    {
        return match ($this) {
            self::Purchase25 => __('Inköp med 25 % moms'),
            self::Purchase12 => __('Inköp med 12 % moms'),
            self::Purchase6 => __('Inköp med 6 % moms'),
            self::PurchaseNoVat => __('Inköp utan moms'),
            self::Sale25 => __('Försäljning med 25 % moms'),
            self::Sale12 => __('Försäljning med 12 % moms'),
            self::Sale6 => __('Försäljning med 6 % moms'),
            self::BankFee => __('Bankavgift'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Purchase25, self::Purchase12, self::Purchase6 => __('Betalt från företagskontot. Momsen räknas ut, och du väljer kostnadskontot.'),
            self::PurchaseNoVat => __('Betalt från företagskontot, utan moms. Du väljer kostnadskontot.'),
            self::Sale25, self::Sale12, self::Sale6 => __('Betalt till företagskontot. Momsen räknas ut.'),
            self::BankFee => __('Avgift som banken har dragit från företagskontot.'),
        };
    }

    /**
     * The rows for the total amount, including VAT. An account of null means
     * "choose an account".
     *
     * @return list<array{account: ?int, debit_ore: int, credit_ore: int}>
     */
    public function rows(Money $total): array
    {
        $ore = $total->ore;

        return match ($this) {
            self::Purchase25 => $this->purchase($total, 2500, 2641),
            self::Purchase12 => $this->purchase($total, 1200, 2641),
            self::Purchase6 => $this->purchase($total, 600, 2641),
            self::PurchaseNoVat => [
                ['account' => null, 'debit_ore' => $ore, 'credit_ore' => 0],
                ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => $ore],
            ],
            self::Sale25 => $this->sale($total, 2500, 3001, 2611),
            self::Sale12 => $this->sale($total, 1200, 3002, 2621),
            self::Sale6 => $this->sale($total, 600, 3003, 2631),
            self::BankFee => [
                ['account' => 6570, 'debit_ore' => $ore, 'credit_ore' => 0],
                ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => $ore],
            ],
        };
    }

    /** @return list<array{account: ?int, debit_ore: int, credit_ore: int}> */
    private function purchase(Money $total, int $rate, int $vatAccount): array
    {
        $vat = $total->vatIncluded($rate);

        return [
            ['account' => null, 'debit_ore' => $total->minus($vat)->ore, 'credit_ore' => 0],
            ['account' => $vatAccount, 'debit_ore' => $vat->ore, 'credit_ore' => 0],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => $total->ore],
        ];
    }

    /** @return list<array{account: ?int, debit_ore: int, credit_ore: int}> */
    private function sale(Money $total, int $rate, int $revenueAccount, int $vatAccount): array
    {
        $vat = $total->vatIncluded($rate);

        return [
            ['account' => 1930, 'debit_ore' => $total->ore, 'credit_ore' => 0],
            ['account' => $revenueAccount, 'debit_ore' => 0, 'credit_ore' => $total->minus($vat)->ore],
            ['account' => $vatAccount, 'debit_ore' => 0, 'credit_ore' => $vat->ore],
        ];
    }
}
