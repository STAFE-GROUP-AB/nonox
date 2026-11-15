<?php

declare(strict_types=1);

use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Stripe\Domain\StripeAmount;
use App\Modules\Saas\Stripe\Domain\SubscriptionContract;
use App\Modules\Saas\Stripe\Domain\WebhookSignature;
use Tests\Support\StripeFake;

/*
 * ADR-080 step 2, the pure parts: Stripe's minor units, a subscription as a
 * contract, and the webhook signature. Synthetic objects only.
 */

it('turns Stripe minor units into hundredths, zero-decimal currencies included', function () {
    expect(StripeAmount::toHundredths(1999, 'sek'))->toBe(1999)
        ->and(StripeAmount::toHundredths(1999, 'EUR'))->toBe(1999)
        // docs.stripe.com/currencies: "Enter 10 to charge 10 JPY".
        ->and(StripeAmount::toHundredths(500, 'jpy'))->toBe(50_000)
        ->and(StripeAmount::toHundredths(10, 'KRW'))->toBe(1_000)
        // ISK and UGX are sent as two-decimal values with 00.
        ->and(StripeAmount::toHundredths(500, 'isk'))->toBe(500)
        ->and(StripeAmount::toHundredths(500, 'ugx'))->toBe(500)
        // Three decimals to two, half up.
        ->and(StripeAmount::toHundredths(1005, 'kwd'))->toBe(101)
        ->and(StripeAmount::toHundredths(1004, 'bhd'))->toBe(100);
});

it('multiplies a decimal unit amount without floats, half up', function () {
    expect(StripeAmount::times('1999', 3))->toBe(5997)
        ->and(StripeAmount::times('0.5', 3))->toBe(2)
        ->and(StripeAmount::times('12.345', 2))->toBe(25)
        ->and(StripeAmount::afterPercentOff(10_000, 25))->toBe(7_500)
        ->and(StripeAmount::afterPercentOff(999, 12.5))->toBe(874);
});

it('reads a monthly subscription as one line in its currency', function () {
    $fake = new StripeFake;
    $subscription = $fake->subscription('sub_A', 'cus_A', 49_900, 'sek', 'month', 1, 2);
    $state = SubscriptionContract::from($subscription, []);

    expect($state['status'])->toBe(ContractStatus::Active)
        ->and($state['excluded'])->toBeNull()
        ->and($state['starts_on'])->toBe('2026-01-01')
        ->and($state['cancel_effective_on'])->toBeNull()
        ->and($state['cause'])->toBe(VersionCause::Import)
        ->and($state['lines'])->toHaveCount(1)
        ->and(RevenueLine::fromArray($state['lines'][0])->monthlyMinorAt('2026-01-31'))->toBe(99_800);
});

it('normalises yearly, weekly and zero-decimal subscriptions to a month', function () {
    $fake = new StripeFake;
    $yearly = SubscriptionContract::from($fake->subscription('sub_Y', 'cus_A', 120_000, 'sek', 'year'), []);
    $weekly = SubscriptionContract::from($fake->subscription('sub_W', 'cus_A', 1_000, 'sek', 'week'), []);
    $yen = SubscriptionContract::from($fake->subscription('sub_J', 'cus_A', 1_200, 'jpy'), []);

    expect(RevenueLine::fromArray($yearly['lines'][0])->monthlyMinorAt('2026-01-31'))->toBe(10_000)
        ->and(RevenueLine::fromArray($weekly['lines'][0])->monthlyMinorAt('2026-01-31'))->toBe(4_333)
        ->and($yen['currency'])->toBe('JPY')
        ->and(RevenueLine::fromArray($yen['lines'][0])->amountMinor)->toBe(120_000);
});

it('counts a trial as 0 until it ends, metered prices outside MRR, and a tiered price not at all', function () {
    $fake = new StripeFake;
    $trial = SubscriptionContract::from($fake->subscription('sub_T', 'cus_A', 10_000, 'sek', 'month', 1, 1, ['status' => 'trialing', 'trial_end' => strtotime('2026-02-15 12:00:00 UTC')]), []);
    $metered = SubscriptionContract::from($fake->subscription('sub_M', 'cus_A', 0, 'sek', 'month', 1, 1, ['items' => ['data' => [StripeFake::item('si_m', 'price_m', 'prod_m', 5, 'sek', 'month', 1, 1, 'metered')]]]), []);
    $tiered = SubscriptionContract::from($fake->subscription('sub_X', 'cus_A', 0, 'sek', 'month', 1, 1, ['items' => ['data' => [StripeFake::item('si_x', 'price_x', 'prod_x', null, 'sek', 'month', 1, 1, 'licensed', 'tiered')]]]), []);
    $line = RevenueLine::fromArray($trial['lines'][0]);

    expect($line->monthlyMinorAt('2026-01-31'))->toBe(0)
        ->and($line->monthlyMinorAt('2026-02-28'))->toBe(10_000)
        ->and(RevenueLine::fromArray($metered['lines'][0])->usage)->toBeTrue()
        ->and(RevenueLine::fromArray($metered['lines'][0])->monthlyMinorAt('2026-01-31'))->toBe(0)
        ->and($tiered['excluded'])->toBe(ExclusionReason::StripeUnsupportedPrice)
        ->and($tiered['lines'])->toBe([]);
});

it('ends a cancelled subscription at the end of its paid period, and pauses a paused one', function () {
    $fake = new StripeFake;
    $atPeriodEnd = SubscriptionContract::from($fake->subscription('sub_C', 'cus_A', 10_000, 'sek', 'month', 1, 1, ['cancel_at_period_end' => true]), []);
    $ended = SubscriptionContract::from($fake->subscription('sub_E', 'cus_A', 10_000, 'sek', 'month', 1, 1, ['status' => 'canceled', 'ended_at' => strtotime('2026-03-01 00:00:00 UTC')]), []);
    $paused = SubscriptionContract::from($fake->subscription('sub_P', 'cus_A', 10_000, 'sek', 'month', 1, 1, ['pause_collection' => ['behavior' => 'void', 'resumes_at' => null]]), []);
    $incomplete = SubscriptionContract::from($fake->subscription('sub_I', 'cus_A', 10_000, 'sek', 'month', 1, 1, ['status' => 'incomplete']), []);

    expect($atPeriodEnd['cancel_effective_on'])->toBe('2026-02-01')
        ->and($atPeriodEnd['cause'])->toBe(VersionCause::CancelScheduled)
        ->and($ended['cancel_effective_on'])->toBe('2026-03-01')
        ->and($ended['cause'])->toBe(VersionCause::Ended)
        ->and($paused['status'])->toBe(ContractStatus::Paused)
        ->and($incomplete['excluded'])->toBe(ExclusionReason::StripeNotStarted);
});

it('applies recurring discounts and ignores a once coupon', function () {
    $fake = new StripeFake;
    $coupons = [
        'C25' => ['id' => 'C25', 'percent_off' => 25, 'amount_off' => null, 'currency' => null, 'duration' => 'repeating', 'duration_in_months' => 3],
        'ONCE' => ['id' => 'ONCE', 'percent_off' => 50, 'amount_off' => null, 'currency' => null, 'duration' => 'once'],
        'OFF100' => ['id' => 'OFF100', 'percent_off' => null, 'amount_off' => 10_000, 'currency' => 'sek', 'duration' => 'forever'],
    ];
    $subscription = $fake->subscription('sub_D', 'cus_A', 40_000, 'sek', 'month', 1, 1, [
        'discounts' => [
            ['id' => 'di_1', 'object' => 'discount', 'source' => ['type' => 'coupon', 'coupon' => 'ONCE'], 'end' => null],
            ['id' => 'di_2', 'object' => 'discount', 'source' => ['type' => 'coupon', 'coupon' => 'OFF100'], 'end' => null],
        ],
    ]);
    $subscription['items']['data'][0]['discounts'] = [['id' => 'di_3', 'object' => 'discount', 'source' => ['type' => 'coupon', 'coupon' => 'C25'], 'end' => strtotime('2026-04-01 00:00:00 UTC')]];
    $state = SubscriptionContract::from($subscription, $coupons);
    $lines = array_map(fn (array $line): RevenueLine => RevenueLine::fromArray($line), $state['lines']);
    $at = fn (string $instant): int => array_sum(array_map(fn (RevenueLine $line): int => $line->monthlyMinorAt($instant), $lines));

    // 400 kr, 25 % off until April, 100 kr off for ever; the once coupon is not a recurring discount.
    expect($lines)->toHaveCount(2)
        ->and($at('2026-01-31'))->toBe(20_000)
        ->and($at('2026-04-30'))->toBe(30_000);
});

it('verifies Stripe signatures as Stripe describes and refuses everything else', function () {
    $body = '{"id":"evt_1","object":"event"}';
    $secret = 'whsec_testsecret000000000000000000';
    $now = 1_800_000_000;
    $header = WebhookSignature::sign($body, $secret, $now);

    expect(WebhookSignature::verify($header, $body, [$secret], $now))->toBeTrue()
        // Several v1 signatures while a secret is rolled: one match is enough.
        ->and(WebhookSignature::verify("t={$now},v1=".str_repeat('0', 64).','.substr($header, strpos($header, 'v1=')), $body, [$secret], $now))->toBeTrue()
        // Unknown debug entries are allowed.
        ->and(WebhookSignature::verify($header.',secret=whsec_****,payload=abc', $body, [$secret], $now))->toBeTrue()
        // Wrong secret, altered body, an old timestamp, v0 only, no timestamp.
        ->and(WebhookSignature::verify($header, $body, ['whsec_other0000000000000000000000'], $now))->toBeFalse()
        ->and(WebhookSignature::verify($header, $body.' ', [$secret], $now))->toBeFalse()
        ->and(WebhookSignature::verify($header, $body, [$secret], $now + 301))->toBeFalse()
        ->and(WebhookSignature::verify("t={$now},v0=".hash_hmac('sha256', "{$now}.{$body}", $secret), $body, [$secret], $now))->toBeFalse()
        ->and(WebhookSignature::verify('v1='.hash_hmac('sha256', "{$now}.{$body}", $secret), $body, [$secret], $now))->toBeFalse()
        ->and(WebhookSignature::verify($header, $body, [$secret], $now, 0))->toBeFalse();
});
