<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Actions\Invoicing\SaveCustomer;
use App\Events\CustomerAnonymised;
use App\Models\AuditLog;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Modules\Saas\Livewire\StripePage;
use App\Modules\Saas\Stripe\Actions\ConnectStripe;
use App\Modules\Saas\Stripe\Actions\LinkStripeCustomer;
use App\Modules\Saas\Stripe\Actions\SaveStripeWebhookSecret;
use App\Modules\Saas\Stripe\Domain\WebhookSignature;
use App\Modules\Saas\Stripe\Jobs\SyncStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Support\Instance\InstanceSettings;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * ADR-080 step 2, the review fixes: the installation's switch (dark on the
 * hosted service), a rotated key is never revoked by an old run, erasure that
 * stays erased, register extracts, and the smaller security fixes.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    $this->fake = StripeFake::install();
    [$this->owner, $this->company] = SaasStripe::company();
});

it('is off on a new installation, and while it is off nothing of Stripe works', function () {
    InstanceSettings::current()->forget();
    InstanceSetting::query()->where('key', InstanceSettings::STRIPE_ENABLED)->delete();
    InstanceSettings::current()->forget();
    expect(InstanceSettings::current()->stripeEnabled())->toBeFalse();

    expect(fn () => SaasStripe::connect($this->owner, $this->company, $this->fake))
        ->toThrow(AuthorizationException::class, 'Kopplingen till Stripe finns inte än i den här installationen.');

    $this->get(route('saas.stripe'))->assertOk()
        ->assertSee('Kopplingen till Stripe finns inte än i den här installationen.')
        ->assertDontSee('Begränsad nyckel')
        ->assertDontSee('Slå på Stripe i NONOX Connect');

    expect($this->fake->log)->toBe([]);
});

it('keeps an existing connection inert while the installation\'s switch is off', function () {
    $this->fake->customer('cus_A');
    $this->fake->subscription('sub_A', 'cus_A', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    app(SaveStripeWebhookSecret::class)($this->owner, $this->company, $connection, 'whsec_FakeSigningSecretA0000000000');
    $requests = count($this->fake->log);

    SaasStripe::instance(false);
    SyncStripe::dispatchSync($this->company->id, $connection->id);
    $body = (string) json_encode(['id' => 'evt_off', 'type' => 'customer.updated', 'created' => now()->getTimestamp(), 'livemode' => false, 'data' => ['object' => ['id' => 'cus_A']]]);
    $this->call('POST', "/webhooks/stripe/{$connection->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($body, 'whsec_FakeSigningSecretA0000000000', now()->getTimestamp())], $body)->assertNotFound();
    Livewire::test(StripePage::class)->call('syncNow');

    expect(count($this->fake->log))->toBe($requests)
        ->and(StripeSyncRun::query()->count())->toBe(1);

    SaasStripe::instance(true);
    SyncStripe::dispatchSync($this->company->id, $connection->id);
    expect(StripeSyncRun::query()->count())->toBe(2);
});

it('is switched on and off for the installation in /hq', function () {
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    SaasStripe::instance(false);

    $changed = app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => true, 'registration_open' => false, 'stripe_enabled' => true]);

    expect($changed)->toBe([InstanceSettings::STRIPE_ENABLED])
        ->and(InstanceSettings::current()->stripeEnabled())->toBeTrue();
});

it('never revokes a key that replaced the one an old run used', function () {
    $this->fake->customer('cus_A');
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    $stale = $connection->fresh();

    // The key is rotated in Stripe and in NONOX while a run still holds the old one.
    $this->fake->key = 'rk_test_FakeRestrictedKey000000000099';
    app(ConnectStripe::class)($this->owner, $this->company, $this->fake->key);
    $run = app(StripeSync::class)->run($stale);
    $fresh = $connection->fresh();

    expect($run->status)->toBe(StripeSyncRun::INTERRUPTED)
        ->and($run->error)->toBe('key_changed')
        ->and($fresh->status)->toBe(StripeConnection::ACTIVE)
        ->and($fresh->key_version)->toBe(2)
        ->and($fresh->secret_key)->toBe('rk_test_FakeRestrictedKey000000000099')
        ->and(AuditLog::query()->where('action', 'stripe.revoked_by_provider')->exists())->toBeFalse();

    // A 401 to a run with the current key still revokes.
    $this->fake->revoked = true;
    app(StripeSync::class)->run($fresh);
    expect($connection->fresh()->status)->toBe(StripeConnection::REVOKED);
});

it('keeps an anonymised customer\'s Stripe customer erased when Stripe sends it again', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Nummer AB', 'payment_terms_days' => 30]);
    $this->fake->customer('cus_1', ['email' => 'person@example.test', 'metadata' => ['nonox_customer_number' => (string) $customer->number]]);
    $this->fake->subscription('sub_1', 'cus_1', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);

    event(new CustomerAnonymised($this->company->id, $customer->id));
    expect(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(0);

    $this->fake->event('evt_upd', 'customer.updated', 'cus_1', now()->getTimestamp());
    $this->fake->event('evt_sub', 'customer.subscription.updated', 'sub_1', now()->getTimestamp());
    app(StripeSync::class)->run($connection->fresh());
    $link = StripeCustomerLink::query()->sole();

    expect($link->erased_at)->not->toBeNull()
        ->and($link->name)->toBeNull()
        ->and($link->email)->toBeNull()
        ->and($link->address)->toBeNull()
        ->and($link->tax_ids)->toBeNull()
        ->and($link->nonox_customer_number)->toBeNull()
        ->and($link->customer_id)->toBe($customer->id)
        // The erased customer's subscriptions are not kept raw again.
        ->and(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(0);
});

it('erases a Stripe customer that is not linked on request, and gives its register extract', function () {
    $this->fake->customer('cus_U', ['name' => 'Privat Person', 'email' => 'privat@example.test']);
    $this->fake->subscription('sub_U', 'cus_U', 10_000);
    SaasStripe::connect($this->owner, $this->company, $this->fake);
    $link = StripeCustomerLink::query()->sole();

    $extract = $this->get(route('saas.stripe.export', ['link' => $link->id]))->assertOk()->json();
    expect($extract['stripe_kund'])->toMatchArray(['stripe_kund_id' => 'cus_U', 'namn' => 'Privat Person', 'e_post' => 'privat@example.test']);

    Livewire::test(StripePage::class)->call('erase', $link->id);
    $link->refresh();

    expect($link->name)->toBeNull()
        ->and($link->email)->toBeNull()
        ->and($link->erased_at)->not->toBeNull()
        ->and(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'stripe.customer_erased')->exists())->toBeTrue()
        ->and(fn () => app(LinkStripeCustomer::class)->createCustomer($this->owner, $this->company, $link))->toThrow(ValidationException::class);
});

it('puts the linked Stripe customers in the customer\'s register extract, and answers 404 for another company\'s', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Nummer AB', 'payment_terms_days' => 30]);
    $this->fake->customer('cus_1', ['email' => 'kund@example.test', 'metadata' => ['nonox_customer_number' => (string) $customer->number]]);
    $this->fake->subscription('sub_1', 'cus_1', 10_000);
    SaasStripe::connect($this->owner, $this->company, $this->fake);

    expect(DataSubjectExport::customer($customer)['stripe'])->toHaveCount(1)
        ->and(DataSubjectExport::customer($customer)['stripe'][0])->toMatchArray(['stripe_kund_id' => 'cus_1', 'e_post' => 'kund@example.test', 'kopplad_genom' => StripeCustomerLink::BY_METADATA]);

    $fakeB = StripeFake::install(2);
    [$ownerB, $companyB] = SaasStripe::company();
    $fakeB->customer('cus_B');
    $fakeB->subscription('sub_B', 'cus_B', 10_000);
    SaasStripe::connect($ownerB, $companyB, $fakeB);
    $foreign = CurrentCompany::run($companyB, fn (): int => StripeCustomerLink::query()->sole()->id);

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->get(route('saas.stripe.export', ['link' => $foreign]))->assertNotFound();
});

it('clears a customer deleted in Stripe at once and keeps only the tax ids matching uses', function () {
    $this->fake->customer('cus_D', ['name' => 'Borta AB', 'email' => 'borta@example.test', 'tax_ids' => ['object' => 'list', 'data' => [
        ['type' => 'eu_vat', 'value' => 'SE556677889901', 'country' => 'SE'],
        ['type' => 'us_ein', 'value' => '12-3456789', 'country' => 'US'],
    ]]]);
    $this->fake->subscription('sub_D', 'cus_D', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);

    expect(StripeCustomerLink::query()->sole()->tax_ids)->toBe([['type' => 'eu_vat', 'value' => 'SE556677889901', 'country' => 'SE']]);

    $this->fake->customers['cus_D'] = ['id' => 'cus_D', 'object' => 'customer', 'deleted' => true];
    $this->fake->event('evt_del', 'customer.deleted', 'cus_D', now()->getTimestamp());
    app(StripeSync::class)->run($connection->fresh());
    $link = StripeCustomerLink::query()->sole();

    expect($link->deleted_in_stripe)->toBeTrue()
        ->and($link->name)->toBeNull()
        ->and($link->email)->toBeNull()
        ->and($link->tax_ids)->toBeNull()
        ->and($link->country)->toBe('SE')
        ->and($link->erased_at)->not->toBeNull();
});

it('does not start the import again when it stopped after its last page', function () {
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    $connection->forceFill(['initial_import_done_at' => null, 'cursor' => ['stage' => 'done', 'after' => null, 'watermark' => now()->getTimestamp()]])->save();
    $before = collect($this->fake->log)->filter(fn (string $line): bool => str_starts_with($line, 'GET /v1/coupons '))->count();

    $run = app(StripeSync::class)->run($connection->fresh());

    expect($run->status)->toBe(StripeSyncRun::OK)
        ->and($connection->fresh()->initial_import_done_at)->not->toBeNull()
        ->and(collect($this->fake->log)->filter(fn (string $line): bool => str_starts_with($line, 'GET /v1/coupons '))->count())->toBe($before);
});

it('pages the lists of Stripe customers in the query string', function () {
    foreach (range(1, 30) as $n) {
        $this->fake->customer(sprintf('cus_%02d', $n), ['name' => sprintf('Kund nummer %02d', $n)]);
        $this->fake->subscription(sprintf('sub_%02d', $n), sprintf('cus_%02d', $n), 10_000);
    }

    SaasStripe::connect($this->owner, $this->company, $this->fake);

    $this->get(route('saas.stripe'))->assertOk()->assertSee('Kund nummer 25')->assertDontSee('Kund nummer 26');
    $this->get(route('saas.stripe', ['kunder' => 2]))->assertOk()->assertSee('Kund nummer 26')->assertDontSee('Kund nummer 25');
});

it('never sends the key or the signing secret back to the browser', function () {
    $component = Livewire::test(StripePage::class)->set('key', StripeFake::KEY)->set('webhookSecret', 'whsec_FakeSigningSecretA0000000000');

    expect($component->get('key'))->toBe('')
        ->and($component->get('webhookSecret'))->toBe('')
        ->and(json_encode($component->snapshot))->not->toContain('rk_test_')->not->toContain('whsec_');
});
