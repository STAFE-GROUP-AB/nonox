<?php

declare(strict_types=1);

use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Support\Connect\ConnectAddons;
use App\Support\Privacy\DataSubjectExport;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * ADR-080 step 2, the re-check: a running sync stops writing and stops
 * asking Stripe the moment its connection ends or Stripe is switched off;
 * a customer deleted in Stripe loses its raw subscriptions; the register
 * extract and erasure stay on the page while Stripe is off; the extract
 * shows what was recorded about the subscriptions.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    $this->fake = StripeFake::install();
    [$this->owner, $this->company] = SaasStripe::company();
});

it('stops an import that is running when someone disconnects: nothing counts again and Stripe is not asked again', function () {
    foreach (range(1, 120) as $n) {
        $this->fake->customer(sprintf('cus_%03d', $n));
        $this->fake->subscription(sprintf('sub_%03d', $n), sprintf('cus_%03d', $n), 10_000);
    }

    $requestsAtSwitch = null;
    // The second page of subscriptions is asked for; meanwhile a person disconnects.
    $this->fake->hooks['GET /v1/subscriptions limit=100&status=all'.'&expand%5B0%5D=data.discounts&expand%5B1%5D=data.items.data.discounts&starting_after='] = function () use (&$requestsAtSwitch): void {
        $requestsAtSwitch = count($this->fake->log);
        app(EndStripeConnection::class)($this->owner, $this->company, StripeConnection::query()->sole());
    };

    SaasStripe::connect($this->owner, $this->company, $this->fake);

    $run = StripeSyncRun::query()->sole();
    $versions = SaasContractVersion::query()->where('source_type', SourceType::StripeSubscription->value)->orderBy('id')->get();
    $firstOff = $versions->first(fn (SaasContractVersion $version): bool => $version->excluded === ExclusionReason::StripeDisconnected);
    $latest = $versions->groupBy('source_id')->map->last();

    expect($requestsAtSwitch)->not->toBeNull()
        ->and(count($this->fake->log))->toBe($requestsAtSwitch)
        ->and($run->status)->toBe(StripeSyncRun::FAILED)
        ->and($run->error)->toBe('ended')
        ->and($firstOff)->not->toBeNull()
        // No counting version after the connection was switched off, and every subscription read is switched off.
        ->and($versions->filter(fn (SaasContractVersion $version): bool => $version->id > $firstOff->id && $version->excluded === null))->toBeEmpty()
        ->and($latest->every(fn (SaasContractVersion $version): bool => $version->excluded === ExclusionReason::StripeDisconnected))->toBeTrue()
        ->and($latest)->toHaveCount(100);
});

it('stops handling events that are running when Stripe is switched off for the installation', function () {
    foreach (range(1, 3) as $n) {
        $this->fake->customer("cus_{$n}");
        $this->fake->subscription("sub_{$n}", "cus_{$n}", 10_000);
    }

    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);
    $before = SaasContractVersion::query()->count();

    foreach (range(1, 3) as $n) {
        $this->fake->subscriptions["sub_{$n}"]['items']['data'][0]['quantity'] = 4;
        $this->fake->event("evt_{$n}", 'customer.subscription.updated', "sub_{$n}", now()->getTimestamp());
    }

    $requestsAtSwitch = null;
    $this->fake->hooks['GET /v1/subscriptions/sub_1 '] = function () use (&$requestsAtSwitch): void {
        $requestsAtSwitch = count($this->fake->log);
        SaasStripe::instance(false);
    };

    $run = app(StripeSync::class)->run($connection->fresh());

    expect($requestsAtSwitch)->not->toBeNull()
        ->and(count($this->fake->log))->toBe($requestsAtSwitch)
        ->and($run->status)->toBe(StripeSyncRun::FAILED)
        ->and($run->error)->toBe('ended')
        ->and(SaasContractVersion::query()->count())->toBe($before)
        ->and(StripeEvent::query()->whereNotNull('processed_at')->count())->toBe(0);
});

it('stops a run whose key was replaced before it writes', function () {
    $this->fake->customer('cus_1');
    $this->fake->subscription('sub_1', 'cus_1', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    $this->fake->subscriptions['sub_1']['items']['data'][0]['quantity'] = 2;
    $this->fake->event('evt_1', 'customer.subscription.updated', 'sub_1', now()->getTimestamp());
    $this->fake->hooks['GET /v1/subscriptions/sub_1 '] = fn () => StripeConnection::query()->whereKey($connection->id)->update(['key_version' => 9]);

    $run = app(StripeSync::class)->run($connection);

    expect($run->error)->toBe('ended')
        ->and(SaasContractVersion::query()->count())->toBe(1);
});

it('deletes the raw subscriptions of a customer deleted in Stripe', function () {
    $this->fake->customer('cus_D', ['name' => 'Borta AB']);
    $this->fake->subscription('sub_D', 'cus_D', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);
    expect(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(1);

    $this->fake->customers['cus_D'] = ['id' => 'cus_D', 'object' => 'customer', 'deleted' => true];
    $this->fake->event('evt_del', 'customer.deleted', 'cus_D', now()->getTimestamp());
    app(StripeSync::class)->run($connection->fresh());

    expect(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(0)
        ->and(StripeCustomerLink::query()->sole()->erased_at)->not->toBeNull()
        ->and(SaasContractVersion::query()->count())->toBe(1);
});

it('keeps the register extract and erasure on the page while Stripe is switched off', function (string $switch) {
    $this->fake->customer('cus_U', ['name' => 'Privat Person']);
    $this->fake->subscription('sub_U', 'cus_U', 10_000);
    SaasStripe::connect($this->owner, $this->company, $this->fake);

    $switch === 'installation'
        ? SaasStripe::instance(false)
        : ConnectAddons::set($this->company, $this->owner, ConnectAddons::STRIPE, false);

    $this->get(route('saas.stripe'))->assertOk()
        ->assertSee('Privat Person')
        ->assertSee('Registerutdrag')
        ->assertSee('Radera uppgifterna')
        ->assertDontSee('Skapa kund')
        ->assertDontSee('Begränsad nyckel')
        ->assertDontSee('Läs in nu');
})->with(['installation', 'add-on']);

it('shows in the register extract what was recorded about the Stripe customer\'s subscriptions', function () {
    $this->fake->customer('cus_U', ['name' => 'Privat Person']);
    $this->fake->subscription('sub_U', 'cus_U', 49_900, 'sek', 'month', 1, 2);
    SaasStripe::connect($this->owner, $this->company, $this->fake);

    $extract = DataSubjectExport::stripeCustomer(StripeCustomerLink::query()->sole());

    expect($extract['prenumerationer'])->toHaveCount(1)
        ->and($extract['prenumerationer'][0])->toMatchArray(['prenumeration' => 'sub_U', 'galler_fran' => '2026-01-01', 'status' => 'ACTIVE'])
        ->and($extract['prenumerationer'][0]['rader'][0])->toMatchArray(['valuta' => 'SEK', 'belopp_hundradelar' => 99_800, 'intervall' => 'month'])
        ->and((string) json_encode($extract))->not->toContain('rk_test_');
});
