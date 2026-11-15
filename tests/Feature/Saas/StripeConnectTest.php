<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Livewire\StripePage;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Stripe\Actions\ConnectStripe;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Modules\Saas\Stripe\Notifications\StripeRevokedNotice;
use App\Modules\Saas\Stripe\Support\StripeClient;
use App\Modules\Saas\Stripe\Support\StripeSleeper;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Support\Connect\ConnectAddons;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * ADR-080 build step 2: connecting Stripe with a restricted read-only key,
 * the refusals, the import and its limits, revocation and disconnection.
 * Against a fake Stripe account; no test calls Stripe.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    $this->fake = StripeFake::install();
    [$this->owner, $this->company] = SaasStripe::company();
    $this->fake->customer('cus_A');
    $this->fake->subscription('sub_A', 'cus_A', 49_900);
});

function connectError(Closure $connect): string
{
    try {
        $connect();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors())->flatten()->first();
    }

    return '';
}

it('refuses secret and publishable keys, malformed keys and test keys in production', function () {
    $connect = fn (string $key): string => connectError(fn () => app(ConnectStripe::class)($this->owner, $this->company, $key));

    expect($connect('sk_live_'.'FakeSecretKey00000000000001'))->toContain('inte en begränsad nyckel')
        ->and($connect('pk_live_FakePublishableKey00000001'))->toContain('inte en begränsad nyckel')
        ->and($connect('rk_live_short'))->toContain('ska börja med rk_live_');

    app()->detectEnvironment(fn (): string => 'production');
    expect($connect(StripeFake::KEY))->toContain('testnyckel');
    app()->detectEnvironment(fn (): string => 'testing');

    expect(StripeConnection::query()->count())->toBe(0)
        ->and($this->fake->log)->toBe([]);
});

it('names every missing read permission and stores nothing', function () {
    $this->fake->forbidden = ['/v1/subscriptions', '/v1/events'];

    expect(connectError(fn () => SaasStripe::connect($this->owner, $this->company, $this->fake)))
        ->toBe('Nyckeln saknar läsbehörighet för Subscriptions, Events. Ändra nyckeln i Stripe så att de har Read, och försök igen.')
        ->and(StripeConnection::query()->count())->toBe(0);
});

it('connects, keeps only the last four characters in clear, and imports the account', function () {
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    $raw = DB::table('stripe_connections')->where('id', $connection->id)->first();

    expect($connection->status)->toBe(StripeConnection::ACTIVE)
        ->and($connection->account_id)->toBe('acct_1FakeAccount00001')
        ->and($connection->key_last4)->toBe('0001')
        ->and($connection->secret_key)->toBe(StripeFake::KEY)
        ->and((string) $raw->secret_key)->not->toContain('rk_test_')
        ->and($connection->toArray())->not->toHaveKey('secret_key')
        ->and($connection->webhook_token)->toHaveLength(48)
        ->and($connection->initial_import_done_at)->not->toBeNull()
        ->and(StripeSyncRun::query()->sole()->status)->toBe(StripeSyncRun::OK)
        ->and(StripeCustomerLink::query()->sole()->stripe_customer_id)->toBe('cus_A');

    $version = SaasContractVersion::query()->where('source_type', SourceType::StripeSubscription->value)->sole();
    expect($version->source_id)->toBe('sub_A')
        ->and($version->effective_from->toDateString())->toBe('2026-01-01')
        ->and($version->customer_id)->toBe(-StripeCustomerLink::query()->sole()->id)
        ->and($version->source_ref)->toMatchArray(['subscription' => 'sub_A', 'customer' => 'cus_A'])
        ->and(AuditLog::query()->where('action', 'stripe.connected')->exists())->toBeTrue();

    // Read-only by construction: every request is a GET.
    expect(collect($this->fake->log)->every(fn (string $line): bool => str_starts_with($line, 'GET ')))->toBeTrue();
});

it('never writes the key to a log, the audit log, a page or the browser', function () {
    $messages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$messages): void {
        $messages[] = $logged->message.json_encode($logged->context);
    });

    $component = Livewire\Livewire::test(StripePage::class)
        ->set('key', StripeFake::KEY)
        ->call('connect');
    $this->fake->revoked = true;
    app(StripeSync::class)->run(StripeConnection::query()->sole());
    $page = $this->get(route('saas.stripe'))->assertOk()->getContent();

    expect(implode("\n", $messages))->not->toContain(StripeFake::KEY)
        ->and(AuditLog::query()->get()->map(fn (AuditLog $log): string => (string) json_encode($log->payload))->implode("\n"))->not->toContain('rk_test_')
        ->and($page)->not->toContain(StripeFake::KEY)
        ->and(json_encode($component->snapshot))->not->toContain(StripeFake::KEY)
        ->and($component->get('key'))->toBe('');
});

it('refuses one Stripe account in a second company without saying which', function () {
    SaasStripe::connect($this->owner, $this->company, $this->fake);
    [$other, $second] = SaasStripe::company();

    expect(connectError(fn () => SaasStripe::connect($other, $second, $this->fake)))
        ->toBe('Det här Stripe-kontot är redan kopplat till ett annat företag i NONOX. Ett Stripe-konto kan vara kopplat till ett företag i taget.')
        ->and(StripeConnection::query()->count())->toBe(0);
});

it('retries a 429 with backoff and goes on', function () {
    $this->fake->failures = [429, 429];

    SaasStripe::connect($this->owner, $this->company, $this->fake);

    expect(StripeConnection::query()->sole()->status)->toBe(StripeConnection::ACTIVE)
        ->and(app(StripeSleeper::class)->waits)->toHaveCount(2)
        ->and(app(StripeSleeper::class)->waits[1])->toBeGreaterThanOrEqual(500);
});

it('stops a sync Stripe keeps refusing and resumes it from the same page', function () {
    foreach (range(1, 150) as $n) {
        $this->fake->customer(sprintf('cus_%03d', $n));
    }

    // The first page of customers comes; the second stays at 503 through every retry.
    $this->fake->failWhen = '/v1/customers limit=100&expand%5B0%5D=data.tax_ids&starting_after=';
    $this->fake->failTimes = StripeClient::MAX_ATTEMPTS;
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();
    $interrupted = StripeSyncRun::query()->sole();

    expect($interrupted->status)->toBe(StripeSyncRun::INTERRUPTED)
        ->and($interrupted->error)->toBe('unavailable')
        ->and($connection->initial_import_done_at)->toBeNull()
        ->and($connection->cursor)->toMatchArray(['stage' => 'customers'])
        ->and(StripeCustomerLink::query()->count())->toBe(100);

    $run = app(StripeSync::class)->run($connection);

    expect($run->status)->toBe(StripeSyncRun::OK)
        ->and(StripeCustomerLink::query()->count())->toBe(151)
        ->and(StripeCustomerLink::query()->distinct()->count('stripe_customer_id'))->toBe(151)
        // Coupons, products and prices were not read again: the run went on from the customers' second page.
        ->and(collect($this->fake->log)->filter(fn (string $line): bool => str_starts_with($line, 'GET /v1/coupons '))->count())->toBe(2)
        ->and(SaasContractVersion::query()->count())->toBe(1);
});

it('revokes the connection on a 401 and tells the members who manage it', function () {
    Notification::fake();
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);
    $this->fake->revoked = true;

    $run = app(StripeSync::class)->run($connection->fresh());
    $connection->refresh();

    expect($run->status)->toBe(StripeSyncRun::FAILED)
        ->and($run->error)->toBe('unauthorized')
        ->and($connection->status)->toBe(StripeConnection::REVOKED)
        ->and($connection->secret_key)->toBeNull()
        ->and(SaasContractVersion::query()->orderByDesc('id')->first()->excluded)->toBe(ExclusionReason::StripeDisconnected)
        ->and(AuditLog::query()->where('action', 'stripe.revoked_by_provider')->exists())->toBeTrue();

    Notification::assertSentTo($this->owner, StripeRevokedNotice::class);
});

it('disconnects: the key is erased at once and the subscriptions stop counting as a source switched off', function () {
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);
    Saas::recompute($this->company);
    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(49_900);

    Saas::at('2026-02-10');
    app(EndStripeConnection::class)($this->owner, $this->company, $connection->fresh());
    Saas::recompute($this->company);
    $connection->refresh();

    expect($connection->status)->toBe(StripeConnection::DISCONNECTED)
        ->and($connection->secret_key)->toBeNull()
        ->and($connection->webhook_secret)->toBeNull()
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(0)
        ->and(Saas::month('2026-02-01')->churned_customers)->toBe(0)
        ->and(Saas::month('2026-02-01')->source_ore)->toBe(-49_900);
});

it('takes up the earlier connection, with its customer links, when the same account is connected again', function () {
    $first = SaasStripe::connect($this->owner, $this->company, $this->fake);
    $link = StripeCustomerLink::query()->sole();
    app(EndStripeConnection::class)($this->owner, $this->company, $first->fresh());

    $again = SaasStripe::connect($this->owner, $this->company, $this->fake);
    Saas::recompute($this->company);

    expect($again->id)->toBe($first->id)
        ->and(StripeCustomerLink::query()->sole()->id)->toBe($link->id)
        ->and(SaasContractVersion::query()->orderByDesc('id')->first()->excluded)->toBeNull()
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(49_900);
});

it('needs settings:manage and saas:manage, NONOX SaaS on and Stripe switched on in NONOX Connect', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    expect(fn () => app(ConnectStripe::class)($bookkeeper, $this->company, StripeFake::KEY))->toThrow(AuthorizationException::class);

    ConnectAddons::set($this->company, $this->owner, ConnectAddons::STRIPE, false);
    expect(fn () => SaasStripe::connect($this->owner, $this->company, $this->fake))->toThrow(AuthorizationException::class, 'Stripe är inte påslaget i NONOX Connect för företaget.')
        ->and($this->fake->log)->toBe([]);
});

it('shows the price as the provider price plus 10 % on the page', function () {
    ConnectAddons::set($this->company, $this->owner, ConnectAddons::STRIPE, false);

    $this->get(route('saas.stripe'))->assertOk()
        ->assertSee('Leverantörens pris')
        ->assertSee('Påslag 10 %')
        ->assertSee('Slå på Stripe i NONOX Connect');

    Livewire\Livewire::test(StripePage::class)->call('enableAddon');

    expect(ConnectAddons::isOn($this->company, ConnectAddons::STRIPE))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'connect.addon_enabled')->count())->toBe(2);
});
