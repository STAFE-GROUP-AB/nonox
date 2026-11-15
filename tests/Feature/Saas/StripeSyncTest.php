<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Events\CustomerAnonymised;
use App\Models\Customer;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Livewire\StripePage;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Actions\LinkStripeCustomer;
use App\Modules\Saas\Stripe\Actions\PruneStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Saas;
use Tests\Support\SaasStripe;
use Tests\Support\StripeFake;

/*
 * ADR-080 build step 2: the incremental sync, events once only and in any
 * order, customer matching (§7.5) and the provisional customer moved by a
 * later link as Rättelse (SA-R22). Synthetic data, a fake Stripe account.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    $this->fake = StripeFake::install();
    [$this->owner, $this->company] = SaasStripe::company();
});

function stripeCustomer(string $name, array $fields = []): Customer
{
    return app(SaveCustomer::class)(test()->owner, test()->company, ['name' => $name, 'payment_terms_days' => 30, ...$fields]);
}

function stripeVersions(string $subscription): Collection
{
    return SaasContractVersion::query()->where('source_type', SourceType::StripeSubscription->value)->where('source_id', $subscription)->orderBy('id')->get();
}

it('links by customer number and by VAT number, and only suggests by e-mail or name', function () {
    $byNumber = stripeCustomer('Nummer AB');
    $byVat = stripeCustomer('Moms AB', ['org_number' => '556677-8899', 'vat_number' => 'SE556677889901']);
    $byEmail = stripeCustomer('Post AB', ['email' => 'ekonomi@post.example']);
    $byName = stripeCustomer('Namn Aktiebolag');
    stripeCustomer('Dubbel AB', ['email' => 'delad@example.test']);
    stripeCustomer('Dubbel två AB', ['email' => 'delad@example.test']);

    $this->fake->customer('cus_1', ['metadata' => ['nonox_customer_number' => (string) $byNumber->number]]);
    $this->fake->customer('cus_2', ['tax_ids' => ['object' => 'list', 'data' => [['type' => 'eu_vat', 'value' => 'SE 5566778899 01', 'country' => 'SE']]]]);
    $this->fake->customer('cus_3', ['email' => 'Ekonomi@Post.example']);
    $this->fake->customer('cus_4', ['name' => 'namn  aktiebolag']);
    $this->fake->customer('cus_5', ['email' => 'delad@example.test']);

    SaasStripe::connect($this->owner, $this->company, $this->fake);
    $links = StripeCustomerLink::query()->get()->keyBy('stripe_customer_id');

    expect($links['cus_1']->status)->toBe(StripeCustomerLink::LINKED)
        ->and($links['cus_1']->customer_id)->toBe($byNumber->id)
        ->and($links['cus_1']->method)->toBe(StripeCustomerLink::BY_METADATA)
        ->and($links['cus_1']->needsReview())->toBeTrue()
        ->and($links['cus_2']->customer_id)->toBe($byVat->id)
        ->and($links['cus_2']->method)->toBe(StripeCustomerLink::BY_TAX_ID)
        ->and($links['cus_3']->status)->toBe(StripeCustomerLink::SUGGESTED)
        ->and($links['cus_3']->customer_id)->toBeNull()
        ->and($links['cus_3']->suggested_customer_id)->toBe($byEmail->id)
        ->and($links['cus_4']->status)->toBe(StripeCustomerLink::SUGGESTED)
        ->and($links['cus_4']->suggested_customer_id)->toBe($byName->id)
        ->and($links['cus_4']->suggestion_method)->toBe(StripeCustomerLink::BY_NAME)
        // Two customers share the address: nothing is decided.
        ->and($links['cus_5']->status)->toBe(StripeCustomerLink::UNLINKED)
        ->and($links['cus_5']->suggested_customer_id)->toBeNull();
});

it('counts an unlinked Stripe customer provisionally and moves it as Rättelse when linked later', function () {
    $this->fake->customer('cus_U', ['name' => 'Okänd Kund']);
    $this->fake->subscription('sub_U', 'cus_U', 100_000);
    SaasStripe::connect($this->owner, $this->company, $this->fake);
    Saas::recompute($this->company);
    $link = StripeCustomerLink::query()->sole();

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2026-01-01')->customers)->toBe(1)
        ->and(SaasCustomerMonth::query()->sole()->customer_id)->toBe(-$link->id);

    Saas::at('2026-02-12');
    app(SaasLedger::class)->close($this->company, '2026-01-01', $this->owner->id);
    $customer = stripeCustomer('Riktig Kund AB');

    Livewire::test(StripePage::class)->set("numbers.{$link->id}", (string) $customer->number)->call('linkTo', $link->id);
    Saas::recompute($this->company);

    $corrections = SaasCorrection::query()->orderBy('customer_id')->get();
    $january = SaasCustomerMonth::query()->where('month', '2026-01-01')->sole();
    $february = SaasCustomerMonth::query()->where('month', '2026-02-01')->get()->keyBy('customer_id');

    expect($link->fresh()->customer_id)->toBe($customer->id)
        // The closed month is unchanged.
        ->and($january->customer_id)->toBe(-$link->id)
        ->and($january->mrr_ore)->toBe(100_000)
        // The move is booked in the first open month, from the provisional customer to the real one.
        ->and($corrections->map(fn (SaasCorrection $c): array => [$c->customer_id, $c->amount_ore, $c->affects_month->toDateString(), $c->booked_month->toDateString()])->all())
        ->toBe([[-$link->id, -100_000, '2026-01-01', '2026-02-01'], [$customer->id, 100_000, '2026-01-01', '2026-02-01']])
        ->and($february[$customer->id]->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2026-02-01')->mrr_ore)->toBe(100_000)
        ->and(Saas::month('2026-02-01')->new_customers)->toBe(0)
        ->and(app(SaasLedger::class)->verify($this->company))->toBe([]);
});

it('handles an event once, however often it is listed or delivered', function () {
    $this->fake->customer('cus_A');
    $this->fake->subscription('sub_A', 'cus_A', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();

    Saas::at('2026-01-20');
    $this->fake->subscriptions['sub_A']['items']['data'][0]['quantity'] = 3;
    $this->fake->event('evt_1', 'customer.subscription.updated', 'sub_A', now()->getTimestamp());

    app(StripeSync::class)->run($connection->fresh());
    app(StripeSync::class)->run($connection->fresh());
    app(StripeSync::class)->run($connection->fresh());

    $fetches = collect($this->fake->log)->filter(fn (string $line): bool => str_starts_with($line, 'GET /v1/subscriptions/sub_A '))->count();

    expect(StripeEvent::query()->count())->toBe(1)
        ->and(StripeEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and($fetches)->toBe(1)
        ->and(stripeVersions('sub_A'))->toHaveCount(2)
        ->and(stripeVersions('sub_A')->last()->effective_from->toDateString())->toBe('2026-01-20');
});

it('gives the same versions whatever order the events come in', function () {
    $versionsAfter = function (int $account, array $order): array {
        $fake = $account === 1 ? $this->fake : StripeFake::install($account);
        [$owner, $company] = $account === 1 ? [$this->owner, $this->company] : SaasStripe::company();
        $fake->customer('cus_O');
        $fake->subscription('sub_O', 'cus_O', 10_000);
        $connection = SaasStripe::connect($owner, $company, $fake)->fresh();

        // Two changes on the same day: a price change and a cancellation at the period's end.
        $fake->subscriptions['sub_O']['items']['data'][0]['price']['unit_amount_decimal'] = '25000';
        $fake->subscriptions['sub_O']['cancel_at_period_end'] = true;

        foreach ($order as $event) {
            $fake->events = [];
            $fake->event($event, 'customer.subscription.updated', 'sub_O', now()->getTimestamp());
            app(StripeSync::class)->run($connection->fresh());
        }

        return CurrentCompany::run($company, fn (): array => stripeVersions('sub_O')->map(fn (SaasContractVersion $version): array => [
            $version->effective_from->toDateString(),
            $version->cancel_effective_on?->toDateString(),
            $version->lines,
            $version->cause->value,
        ])->all());
    };

    $inOrder = $versionsAfter(1, ['evt_a', 'evt_b']);
    $reversed = $versionsAfter(2, ['evt_b', 'evt_a']);

    expect($inOrder)->toBe($reversed)->and($inOrder)->toHaveCount(2);
});

it('reads the whole account again when the last event is older than Stripe keeps events', function () {
    $this->fake->customer('cus_A');
    $this->fake->subscription('sub_A', 'cus_A', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake)->fresh();

    Saas::at('2026-03-01');
    $this->fake->subscription('sub_B', 'cus_A', 5_000);
    $run = app(StripeSync::class)->run($connection->fresh());

    expect($run->kind)->toBe('FULL')
        ->and(stripeVersions('sub_B'))->toHaveCount(1)
        ->and(stripeVersions('sub_A'))->toHaveCount(1)
        ->and(StripeObject::query()->where('type', StripeObject::SUBSCRIPTION)->count())->toBe(2);
});

it('creates a customer from a Stripe customer and links it', function () {
    $this->fake->customer('cus_N', ['name' => 'Ny Kund AB', 'email' => 'info@nykund.example', 'tax_ids' => ['object' => 'list', 'data' => [['type' => 'eu_vat', 'value' => 'SE556677889901', 'country' => 'SE']]]]);
    $this->fake->subscription('sub_N', 'cus_N', 20_000);
    SaasStripe::connect($this->owner, $this->company, $this->fake);
    $link = StripeCustomerLink::query()->sole();

    $customer = app(LinkStripeCustomer::class)->createCustomer($this->owner, $this->company, $link);

    expect($customer->name)->toBe('Ny Kund AB')
        ->and($customer->email)->toBe('info@nykund.example')
        ->and($customer->org_number)->toBe('556677-8899')
        ->and($link->fresh()->method)->toBe(StripeCustomerLink::BY_CREATED)
        ->and(stripeVersions('sub_N')->last()->customer_id)->toBe($customer->id);
});

it('unlinks: the Stripe customer counts on its own again and is not matched again', function () {
    $customer = stripeCustomer('Nummer AB');
    $this->fake->customer('cus_1', ['metadata' => ['nonox_customer_number' => (string) $customer->number]]);
    $this->fake->subscription('sub_1', 'cus_1', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);
    $link = StripeCustomerLink::query()->sole();
    expect(stripeVersions('sub_1')->last()->customer_id)->toBe($customer->id);

    Livewire::test(StripePage::class)->call('unlink', $link->id);
    $this->fake->event('evt_9', 'customer.updated', 'cus_1', now()->getTimestamp());
    app(StripeSync::class)->run($connection->fresh());

    expect($link->fresh()->status)->toBe(StripeCustomerLink::UNLINKED)
        ->and(stripeVersions('sub_1')->last()->customer_id)->toBe(-$link->id);
});

it('runs the Stripe page with the same number of queries for 5 and 50 Stripe customers', function () {
    $count = function (int $account, int $customers): int {
        $fake = $account === 1 ? $this->fake : StripeFake::install($account);
        [$owner, $company] = $account === 1 ? [$this->owner, $this->company] : SaasStripe::company();

        foreach (range(1, $customers) as $n) {
            $fake->customer("cus_{$n}");
            $fake->subscription("sub_{$n}", "cus_{$n}", 10_000);
        }

        SaasStripe::connect($owner, $company, $fake);
        $this->actingAs($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('saas.stripe'))->assertOk()->assertSee('Inte kopplade');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(1, 5))->toBe($count(2, 50));
});

it('answers 404 for the pages while NONOX SaaS is off, and shows nothing of Stripe without the add-on', function () {
    app(ModuleManager::class)->disable(SaasModule::KEY, $this->company, $this->owner, true);
    $this->get(route('saas.stripe'))->assertNotFound();
    expect(StripeConnection::query()->count())->toBe(0);
});

it('clears the Stripe customers of an anonymised customer at once, and the rest 90 days after the end', function () {
    $customer = stripeCustomer('Nummer AB');
    $this->fake->customer('cus_1', ['email' => 'person@example.test', 'metadata' => ['nonox_customer_number' => (string) $customer->number]]);
    $this->fake->customer('cus_2', ['email' => 'annan@example.test']);
    $this->fake->subscription('sub_1', 'cus_1', 10_000);
    $connection = SaasStripe::connect($this->owner, $this->company, $this->fake);

    event(new CustomerAnonymised($this->company->id, $customer->id));
    $links = StripeCustomerLink::query()->get()->keyBy('stripe_customer_id');
    expect($links['cus_1']->email)->toBeNull()
        ->and($links['cus_1']->name)->toBeNull()
        ->and($links['cus_2']->email)->toBe('annan@example.test');

    app(EndStripeConnection::class)($this->owner, $this->company, $connection->fresh());
    Saas::at('2026-04-01');
    expect(app(PruneStripe::class)())->toBe(['objects' => 0, 'events' => 0, 'customers' => 0]);

    Saas::at('2026-04-20');
    $pruned = app(PruneStripe::class)();

    expect($pruned['objects'])->toBeGreaterThan(0)
        ->and($pruned['customers'])->toBe(1)
        ->and(StripeObject::query()->count())->toBe(0)
        ->and(StripeCustomerLink::query()->whereNotNull('email')->count())->toBe(0)
        // The ledger keeps ids and amounts only and is not touched.
        ->and(stripeVersions('sub_1'))->not->toBeEmpty();
});
