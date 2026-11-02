<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use Carbon\CarbonImmutable;

/**
 * ROT and RUT (skattereduktion för rot- och rutarbete): the customer pays the
 * labour cost less the deduction, and the company asks Skatteverket for the
 * rest. The deduction is a share of the labour cost including VAT, in whole
 * kronor, within a yearly ceiling per buyer.
 *
 * TODO(legal-verify): every figure here — the shares (30 % ROT, 50 % RUT
 * from 2026-01-01), the ceilings (50 000 kr ROT, 75 000 kr ROT and RUT
 * together per person and year), rounding down to whole kronor, and which
 * costs count as labour. The ceiling is only checked against this
 * company's own invoices; the buyer may have used it elsewhere.
 */
final class TaxReduction
{
    public const string ROT = 'ROT';

    public const string RUT = 'RUT';

    /** Kundfordringar – delad faktura: what Skatteverket is asked to pay. */
    public const int RECEIVABLE = 1513;

    /**
     * Övriga kortfristiga skulder: a deduction Skatteverket has paid and that
     * a credit invoice has taken back from the buyer, owed back to
     * Skatteverket (lag (2009:194) 13-14 §§).
     *
     * TODO(legal-verify): 2890 as the account for this debt (#49).
     */
    public const int REPAYABLE = 2890;

    /** @var array<string, list<array{from: string, rate: int}>> share in basis points from a date */
    public const array RATES = [
        self::ROT => [['from' => '2026-01-01', 'rate' => 3000]],
        self::RUT => [['from' => '2026-01-01', 'rate' => 5000]],
    ];

    /** @var array<string, int> öre per buyer and calendar year */
    public const array CEILINGS = [self::ROT => 5_000_000, 'TOTAL' => 7_500_000];

    public static function label(string $type): string
    {
        return $type === self::ROT ? __('ROT-avdrag') : __('RUT-avdrag');
    }

    /** The share in basis points on a date, or null when no rule is known for it. */
    public static function rate(string $type, CarbonImmutable $date): ?int
    {
        $rate = null;

        foreach (self::RATES[$type] ?? [] as $rule) {
            if ($date->toDateString() >= $rule['from']) {
                $rate = $rule['rate'];
            }
        }

        return $rate;
    }

    /**
     * The deduction on a labour cost including VAT, rounded down to whole
     * kronor (towards zero for a credit), and kept within what is left of
     * the ceilings.
     */
    public static function amount(int $labourOre, int $rate, int $roomOre): int
    {
        $sign = $labourOre < 0 ? -1 : 1;
        $amount = intdiv(intdiv(abs($labourOre) * $rate, 10_000), 100) * 100;

        return $sign * ($sign > 0 ? min($amount, max(0, $roomOre)) : $amount);
    }
}
