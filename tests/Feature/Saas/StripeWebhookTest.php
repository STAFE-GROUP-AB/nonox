<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Actions\SaveStripeWebhookSecret;
use App\Modules\Saas\Stripe\Domain\WebhookSignature;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Support\Connect\ConnectAddons;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Testing\TestResponse;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * ADR-080 §7.4: POST /webhooks/stripe/{token}. Found by token first, then the
 * signature; 404 for anything that would reveal a company; idempotent per
 * event id; the payload only a hint. Company A's token never touches B.
 */

const STRIPE_SECRET_A = 'whsec_FakeSigningSecretA0000000000';
const STRIPE_SECRET_B = 'whsec_FakeSigningSecretB0000000000';

beforeEach(function () {
    Saas::at('2026-01-15');
    $this->fake = StripeFake::install();
    [$this->owner, $this->company] = SaasStripe::company();
    $this->fake->customer('cus_A');
    $this->fake->subscription('sub_A', 'cus_A', 10_000);
    $this->connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    app(SaveStripeWebhookSecret::class)($this->owner, $this->company, $this->connection, STRIPE_SECRET_A);
    $this->connection->refresh();
});

function stripeEventBody(string $id, string $subscription, int $created): string
{
    return (string) json_encode(['id' => $id, 'object' => 'event', 'type' => 'customer.subscription.updated', 'created' => $created, 'livemode' => false, 'data' => ['object' => ['id' => $subscription, 'object' => 'subscription']]]);
}

function postStripe(string $token, string $body, string $secret): TestResponse
{
    return test()->call('POST', "/webhooks/stripe/{$token}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($body, $secret, now()->getTimestamp()),
    ], $body);
}

it('takes a signed event, stores its id once and fetches the object again', function () {
    $this->fake->subscriptions['sub_A']['items']['data'][0]['quantity'] = 2;
    $body = stripeEventBody('evt_W1', 'sub_A', now()->getTimestamp());

    postStripe($this->connection->webhook_token, $body, STRIPE_SECRET_A)->assertOk();
    postStripe($this->connection->webhook_token, $body, STRIPE_SECRET_A)->assertOk();

    $fetches = collect($this->fake->log)->filter(fn (string $line): bool => str_starts_with($line, 'GET /v1/subscriptions/sub_A '))->count();
    $versions = SaasContractVersion::query()->where('source_type', SourceType::StripeSubscription->value)->orderBy('id')->get();

    expect(StripeEvent::query()->count())->toBe(1)
        ->and(StripeEvent::query()->sole()->received_from)->toBe(StripeEvent::FROM_WEBHOOK)
        ->and($fetches)->toBe(1)
        ->and($versions)->toHaveCount(2)
        ->and($versions->last()->lines[0]['amount_minor'])->toBe(20_000);
});

it('refuses a wrong secret, an altered body, an old timestamp and a v0-only signature', function () {
    $token = $this->connection->webhook_token;
    $body = stripeEventBody('evt_W2', 'sub_A', now()->getTimestamp());
    $post = fn (string $header, string $sent) => test()->call('POST', "/webhooks/stripe/{$token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header], $sent);
    $now = now()->getTimestamp();

    postStripe($token, $body, STRIPE_SECRET_B)->assertStatus(400);
    $post(WebhookSignature::sign($body, STRIPE_SECRET_A, $now), $body.' ')->assertStatus(400);
    $post(WebhookSignature::sign($body, STRIPE_SECRET_A, $now - 600), $body)->assertStatus(400);
    $post("t={$now},v0=".hash_hmac('sha256', "{$now}.{$body}", STRIPE_SECRET_A), $body)->assertStatus(400);
    $post('', $body)->assertStatus(400);

    expect(StripeEvent::query()->count())->toBe(0);
});

it('answers 404 for an unknown token and whenever the company may not be reached', function () {
    $body = stripeEventBody('evt_W3', 'sub_A', now()->getTimestamp());

    postStripe(str_repeat('x', 48), $body, STRIPE_SECRET_A)->assertNotFound();
    $this->post('/webhooks/stripe/short')->assertNotFound();

    ConnectAddons::set($this->company, $this->owner, ConnectAddons::STRIPE, false);
    postStripe($this->connection->webhook_token, $body, STRIPE_SECRET_A)->assertNotFound();
    ConnectAddons::set($this->company, $this->owner, ConnectAddons::STRIPE, true);

    app(ModuleManager::class)->disable(SaasModule::KEY, $this->company, $this->owner, true);
    postStripe($this->connection->webhook_token, $body, STRIPE_SECRET_A)->assertNotFound();
    app(ModuleManager::class)->enable(SaasModule::KEY, $this->company, $this->owner);

    app(EndStripeConnection::class)($this->owner, $this->company, $this->connection->fresh());
    postStripe($this->connection->webhook_token, $body, STRIPE_SECRET_A)->assertNotFound();

    expect(StripeEvent::query()->count())->toBe(0);
});

it('never lets company A\'s token touch company B', function () {
    $fakeB = StripeFake::install(2);
    [$ownerB, $companyB] = SaasStripe::company();
    $fakeB->customer('cus_B');
    $fakeB->subscription('sub_B', 'cus_B', 77_700);
    $connectionB = SaasStripe::connect($ownerB, $companyB, $fakeB)->fresh();
    app(SaveStripeWebhookSecret::class)($ownerB, $companyB, $connectionB, STRIPE_SECRET_B);
    $fakeB->subscriptions['sub_B']['items']['data'][0]['quantity'] = 5;
    $before = CompanyScope::bypass(fn () => SaasContractVersion::query()->where('company_id', $companyB->id)->count());

    // B's event, signed with B's secret, sent to A's token: refused.
    postStripe($this->connection->webhook_token, stripeEventBody('evt_B1', 'sub_B', now()->getTimestamp()), STRIPE_SECRET_B)->assertStatus(400);

    // An event about B's subscription, validly signed for A: fetched with A's key, which cannot see it.
    postStripe($this->connection->webhook_token, stripeEventBody('evt_B2', 'sub_B', now()->getTimestamp()), STRIPE_SECRET_A)->assertOk();

    expect(CompanyScope::bypass(fn () => SaasContractVersion::query()->where('company_id', $companyB->id)->count()))->toBe($before)
        ->and(CompanyScope::bypass(fn () => StripeEvent::query()->where('company_id', $companyB->id)->count()))->toBe(0)
        ->and(CurrentCompany::run($this->company, fn () => SaasContractVersion::query()->where('source_id', 'sub_B')->exists()))->toBeFalse()
        ->and(collect($fakeB->log)->filter(fn (string $line): bool => str_contains($line, 'sub_B'))->count())->toBe(0)
        ->and(CompanyScope::bypass(fn () => StripeConnection::query()->where('id', $connectionB->id)->value('status')))->toBe(StripeConnection::ACTIVE);
});
