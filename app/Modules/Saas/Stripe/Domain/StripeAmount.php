<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Domain;

use App\Modules\Saas\Domain\Arithmetic;
use InvalidArgumentException;

/**
 * Stripe amounts to NONOX's minor units (ADR-080 addendum, step 2).
 *
 * Stripe gives every amount in the currency's minor unit, which is not always
 * hundredths: "Enter 10 to charge 10 JPY (or any other zero-decimal
 * currency)" (docs.stripe.com/currencies, "Specify amounts in API requests").
 * The ledger, like NONOX's exchange rates (ExchangeRate::toOre), counts every
 * currency in hundredths of a unit, so:
 *
 * - zero-decimal currencies are multiplied by 100;
 * - ISK and UGX are given by Stripe as two-decimal values "where the decimal
 *   amount is always 00" (the same page, "Special cases"), so they are taken
 *   as they are;
 * - three-decimal currencies (BHD, JOD, KWD, OMR, TND) are divided by 10,
 *   half up;
 * - every other currency is already in hundredths.
 */
final class StripeAmount
{
    /** docs.stripe.com/currencies, "Zero-decimal currencies", read 2026-11-15. */
    public const array ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Zero-decimal in fact, but sent as two-decimal values ("Special cases"). */
    public const array TWO_DECIMAL_REPRESENTED = ['ISK', 'UGX'];

    public const array THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Stripe's amount (in its minor unit of the currency) in hundredths of the currency. */
    public static function toHundredths(int $stripeMinor, string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::TWO_DECIMAL_REPRESENTED, true) => $stripeMinor,
            in_array($currency, self::ZERO_DECIMAL, true) => $stripeMinor * 100,
            in_array($currency, self::THREE_DECIMAL, true) => Arithmetic::divideHalfUp($stripeMinor, 10),
            default => $stripeMinor,
        };
    }

    /**
     * A decimal string of Stripe minor units ("unit_amount_decimal", e.g.
     * "1999.5") times a whole quantity, rounded half up to a whole minor unit.
     * Integer arithmetic on the digits, never a float.
     */
    public static function times(string $decimal, int $quantity): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,12}))?$/', trim($decimal), $match) !== 1) {
            throw new InvalidArgumentException('Not a decimal amount.');
        }

        $fraction = $match[3] ?? '';
        $scale = 10 ** strlen($fraction);
        $scaled = (int) ($match[2].$fraction) * $quantity;
        $whole = Arithmetic::divideHalfUp($scaled, $scale);

        return $match[1] === '-' ? -$whole : $whole;
    }

    /**
     * A percentage off (Stripe's percent_off, a number with at most two
     * decimals) applied to an amount, half up: what remains to pay.
     */
    public static function afterPercentOff(int $amount, float|int|string $percentOff): int
    {
        $basisPoints = (int) round(((float) $percentOff) * 100);

        return $amount - Arithmetic::divideHalfUp($amount * $basisPoints, 10_000);
    }
}
