<?php

declare(strict_types=1);

use App\Models\ConnectAddon;
use App\Modules\Saas\Livewire\StripePage;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * AGENTS.md §13.2 for Stripe in NONOX Connect (ADR-080 §8): company A never
 * sees B's connection, objects, customers, events or sync log, on the page
 * or through Eloquent, and acting on B's Stripe customer answers 404.
 */

beforeEach(function () {
    Saas::at('2026-01-15');

    foreach ([1 => 'a', 2 => 'b'] as $number => $side) {
        $fake = StripeFake::install($number);
        [$owner, $company] = SaasStripe::company();
        $fake->customer("cus_{$side}", ['name' => $side === 'a' ? 'Synlig Alfa AB' : 'Hemlig Beta AB']);
        $fake->subscription("sub_{$side}", "cus_{$side}", $side === 'a' ? 10_000 : 777_700);
        $fake->event("evt_{$side}", 'customer.updated', "cus_{$side}", now()->getTimestamp());
        SaasStripe::connect($owner, $company, $fake);
        $this->{$side} = compact('owner', 'company', 'fake');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('keeps every Stripe model inside the company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([ConnectAddon::class, StripeConnection::class, StripeObject::class, StripeCustomerLink::class, StripeEvent::class, StripeSyncRun::class]);

it('shows only the own company on the Stripe page', function () {
    $this->get(route('saas.stripe'))->assertOk()
        ->assertSee('Synlig Alfa AB')
        ->assertDontSee('Hemlig Beta AB')
        ->assertDontSee('acct_1FakeAccount00002');
});

it('answers 404 for the other company\'s Stripe customer', function () {
    $foreign = CurrentCompany::run($this->b['company'], fn (): int => StripeCustomerLink::query()->sole()->id);

    foreach (['confirm', 'unlink', 'createCustomer', 'acceptSuggestion'] as $action) {
        Livewire::test(StripePage::class)->call($action, $foreign)->assertNotFound();
    }

    expect(CurrentCompany::run($this->b['company'], fn () => StripeCustomerLink::query()->sole()->confirmed_at))->toBeNull();
});
