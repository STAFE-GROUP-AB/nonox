<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Domain;

use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\VersionCause;
use Carbon\CarbonImmutable;

/**
 * A Stripe subscription as a contract of the recurring-revenue ledger
 * (ADR-080 §3.1, §7, addendum step 2). Pure: the subscription and the
 * coupons as Stripe returned them in, the version's fields out. No I/O and
 * no clock.
 *
 * Fields read (API version StripeClient::API_VERSION, Subscription object,
 * docs.stripe.com/api/subscriptions/object, read 2026-11-15):
 *
 * - `status`: active, past_due and unpaid count (SA-R10: an overdue
 *   contract counts until it ends); trialing counts 0 until `trial_end`
 *   (SA-R6); paused, or a `pause_collection`, counts 0 as a pause (SA-R9);
 *   incomplete and incomplete_expired are not started and not counted;
 *   canceled ends at `ended_at`.
 * - `cancel_at`, or `cancel_at_period_end` with the items'
 *   `current_period_end`: the first day it no longer counts (SA-R15). Dates
 *   are taken in Europe/Stockholm.
 * - `items.data[]`: `price.unit_amount_decimal` × `quantity` per
 *   `price.recurring.interval` and `interval_count`; `usage_type` metered is
 *   outside MRR (O3). A tiered price, or one without a unit amount, cannot
 *   be counted per period yet: the subscription is recorded and listed under
 *   Källor (StripeUnsupportedPrice).
 * - `discounts` on the subscription and on each item (expanded Discount
 *   objects, `source.coupon` and `end`) with the Coupon's `percent_off`,
 *   `amount_off`, `currency` and `duration`: a `once` coupon is not a
 *   recurring discount (SA-R5) and is ignored; `forever` lowers the amount;
 *   `repeating` lowers it until `end`. Several time-limited discounts on one
 *   line end together at the earliest end (a management metric, matched
 *   against the invoices in step 3). A subscription-level `amount_off` is a
 *   negative line of its own.
 */
final class SubscriptionContract
{
    public const string TIMEZONE = 'Europe/Stockholm';

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, array<string, mixed>>  $coupons  coupon id => Coupon object
     * @return array{status: ContractStatus, starts_on: string, cancel_effective_on: ?string, excluded: ?ExclusionReason, lines: list<array<string, mixed>>, cause: VersionCause, currency: string}
     */
    public static function from(array $subscription, array $coupons): array
    {
        $status = (string) ($subscription['status'] ?? 'active');
        $currency = strtoupper((string) ($subscription['currency'] ?? 'SEK'));
        $startsOn = self::date((int) ($subscription['start_date'] ?? $subscription['created'] ?? 0));
        $trialUntil = isset($subscription['trial_end']) && is_int($subscription['trial_end']) ? self::date($subscription['trial_end']) : null;
        $items = self::list($subscription['items'] ?? null);

        $excluded = in_array($status, ['incomplete', 'incomplete_expired'], true) ? ExclusionReason::StripeNotStarted : null;
        $lines = [];
        $periodEnds = [];

        // Item discounts first, then the subscription's percentages (Stripe's order); its fixed amounts are lines of their own.
        $subscriptionPercent = array_values(array_filter(self::discounts($subscription['discounts'] ?? null), fn (array $discount): bool => is_numeric(self::coupon($discount, $coupons)['percent_off'] ?? null)));

        foreach ($items as $item) {
            $line = self::line($item, $currency, $trialUntil, $coupons, [...self::discounts($item['discounts'] ?? null), ...$subscriptionPercent]);

            if ($line === null) {
                $excluded ??= ExclusionReason::StripeUnsupportedPrice;

                continue;
            }

            $lines[] = $line->toArray();

            if (isset($item['current_period_end']) && is_int($item['current_period_end'])) {
                $periodEnds[] = $item['current_period_end'];
            }
        }

        foreach (self::discounts($subscription['discounts'] ?? null) as $discount) {
            $coupon = self::coupon($discount, $coupons);

            if ($coupon === null || ! isset($coupon['amount_off']) || ! is_int($coupon['amount_off']) || strtoupper((string) ($coupon['currency'] ?? '')) !== $currency) {
                continue;
            }

            $first = $lines[0] ?? null;

            if ($first === null) {
                continue;
            }

            $off = StripeAmount::toHundredths($coupon['amount_off'], $currency);
            $until = self::discountEnd($discount, $coupon);
            $lines[] = (new RevenueLine(
                'discount:'.(string) ($discount['id'] ?? 'd'),
                '',
                $currency,
                -$off,
                (string) $first['interval_unit'],
                (int) $first['interval_count'],
                false,
                $until,
                $until === null ? null : 0,
                $trialUntil,
            ))->toArray();
        }

        $cancel = match (true) {
            $status === 'canceled' => self::date((int) ($subscription['ended_at'] ?? $subscription['canceled_at'] ?? $subscription['start_date'] ?? 0)),
            isset($subscription['cancel_at']) && is_int($subscription['cancel_at']) => self::date($subscription['cancel_at']),
            ($subscription['cancel_at_period_end'] ?? false) === true && $periodEnds !== [] => self::date(min($periodEnds)),
            default => null,
        };

        $paused = $status === 'paused' || (isset($subscription['pause_collection']) && is_array($subscription['pause_collection']));

        $cause = match (true) {
            $status === 'canceled' => VersionCause::Ended,
            $cancel !== null => VersionCause::CancelScheduled,
            $paused => VersionCause::Paused,
            default => VersionCause::Import,
        };

        return [
            'status' => $paused ? ContractStatus::Paused : ContractStatus::Active,
            'starts_on' => $startsOn,
            'cancel_effective_on' => $cancel,
            'excluded' => $excluded,
            'lines' => $excluded === ExclusionReason::StripeUnsupportedPrice ? [] : $lines,
            'cause' => $cause,
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $coupons
     * @param  list<array<string, mixed>>  $discounts
     */
    private static function line(array $item, string $currency, ?string $trialUntil, array $coupons, array $discounts): ?RevenueLine
    {
        $price = is_array($item['price'] ?? null) ? $item['price'] : [];
        $recurring = is_array($price['recurring'] ?? null) ? $price['recurring'] : null;

        if ($recurring === null) {
            return null;
        }

        $unit = (string) ($recurring['interval'] ?? 'month');
        $count = max(1, (int) ($recurring['interval_count'] ?? 1));
        $usage = ($recurring['usage_type'] ?? 'licensed') === 'metered';
        $key = (string) ($item['id'] ?? 'si');

        if (! in_array($unit, RevenueLine::UNITS, true)) {
            return null;
        }

        if ($usage) {
            return new RevenueLine($key, '', $currency, 0, $unit, $count, true, null, null, $trialUntil);
        }

        $decimal = $price['unit_amount_decimal'] ?? (isset($price['unit_amount']) && is_int($price['unit_amount']) ? (string) $price['unit_amount'] : null);

        if (($price['billing_scheme'] ?? 'per_unit') !== 'per_unit' || ! is_string($decimal) || $decimal === '') {
            return null;
        }

        $quantity = max(0, (int) ($item['quantity'] ?? 1));
        $full = StripeAmount::toHundredths(StripeAmount::times($decimal, $quantity), $currency);
        $forever = $full;
        $limited = $full;
        $until = null;

        foreach ($discounts as $discount) {
            $coupon = self::coupon($discount, $coupons);

            if ($coupon === null) {
                continue;
            }

            $end = self::discountEnd($discount, $coupon);
            $apply = function (int $amount) use ($coupon, $currency): int {
                if (isset($coupon['percent_off']) && is_numeric($coupon['percent_off'])) {
                    return StripeAmount::afterPercentOff($amount, $coupon['percent_off']);
                }

                if (isset($coupon['amount_off']) && is_int($coupon['amount_off']) && strtoupper((string) ($coupon['currency'] ?? '')) === $currency) {
                    return $amount - StripeAmount::toHundredths($coupon['amount_off'], $currency);
                }

                return $amount;
            };

            if ($end === null) {
                $forever = $apply($forever);
                $limited = $apply($limited);

                continue;
            }

            $limited = $apply($limited);
            $until = $until === null ? $end : min($until, $end);
        }

        return $until === null
            ? new RevenueLine($key, '', $currency, $forever, $unit, $count, false, null, null, $trialUntil)
            : new RevenueLine($key, '', $currency, $limited, $unit, $count, false, $until, $forever, $trialUntil);
    }

    /**
     * A discount's end: null for forever, the date of `end` for repeating.
     * Null as well for once, which the callers skip before applying.
     *
     * @param  array<string, mixed>  $discount
     * @param  array<string, mixed>  $coupon
     */
    private static function discountEnd(array $discount, array $coupon): ?string
    {
        if (($coupon['duration'] ?? 'forever') !== 'repeating') {
            return null;
        }

        return isset($discount['end']) && is_int($discount['end']) ? self::date($discount['end']) : null;
    }

    /**
     * The coupon behind a discount, or null when it is not a recurring discount
     * (a `once` coupon, SA-R5) or is unknown.
     *
     * @param  array<string, mixed>  $discount
     * @param  array<string, array<string, mixed>>  $coupons
     * @return ?array<string, mixed>
     */
    private static function coupon(array $discount, array $coupons): ?array
    {
        $source = is_array($discount['source'] ?? null) ? $discount['source'] : [];
        $coupon = $source['coupon'] ?? ($discount['coupon'] ?? null);

        if (is_string($coupon)) {
            $coupon = $coupons[$coupon] ?? null;
        }

        if (! is_array($coupon) || ($coupon['duration'] ?? 'forever') === 'once') {
            return null;
        }

        return $coupon;
    }

    /**
     * Expanded discounts only; an id that was not expanded cannot be read.
     *
     * @return list<array<string, mixed>>
     */
    private static function discounts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        if (! is_array($value) || ! is_array($value['data'] ?? null)) {
            return [];
        }

        return array_values(array_filter($value['data'], 'is_array'));
    }

    /** A Stripe timestamp (UTC seconds) as a date in Sweden. */
    public static function date(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, self::TIMEZONE)->toDateString();
    }
}
