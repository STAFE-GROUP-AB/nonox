<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §9 (P7): a sent quote whose rows changed after they were approved,
 * and so are not approved now, is not shown in the customer portal, neither
 * the document nor its PDF, and the check costs a fixed number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));
    Mail::fake();
    Storage::fake('local');

    $this->scenario = PricingScenario::build(['articles' => ['A1' => ['price_ore' => 10000]], 'customer' => []]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->approver = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($this->owner);
    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 8000]);
    Portal::enable($this->company, $this->owner);
    $this->key = Portal::key($this->company);
    $this->contact = Portal::contact($this->company, $this->owner, $this->customer);
    app(SetPriceFloor::class)($this->owner, $this->company, '15', PriceFloorPolicy::RequireApproval);
    $this->company = $this->company->fresh();
});

function pf7Quote(string $price, ?SalesDocument $document = null): SalesDocument
{
    $s = test();

    return CurrentCompany::run($s->company, fn (): SalesDocument => app(SaveSalesDocument::class)($s->owner, $s->company, SalesDocument::QUOTE, [
        'customer_id' => $s->customer->id,
        'document_date' => '2026-03-15',
        'until_date' => '2026-04-15',
        'rows' => [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'price' => $price, 'price_source' => 'MANUAL', 'vat_rate' => 2500]],
    ], $document));
}

it('hides a sent quote with unapproved prices from the portal, document and PDF, until it is approved again', function () {
    $quote = pf7Quote('90,00');
    $quote->forceFill(['sent_at' => now(), 'sent_to' => 'anna@kontorshuset.test'])->save();
    Portal::signIn($this, $this->contact);

    $show = route('portal.quotes.show', ['portalKey' => $this->key, 'document' => $quote->id]);
    $pdf = route('portal.quotes.pdf', ['portalKey' => $this->key, 'document' => $quote->id]);

    $this->get(route('portal.quotes', ['portalKey' => $this->key]))->assertOk()->assertDontSee($quote->title());
    $this->get($show)->assertNotFound();
    $this->get($pdf)->assertNotFound();

    $approval = app(RequestPriceFloorApproval::class)($this->owner, $this->company, PriceFloorDocument::Quote, $quote->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    $this->get(route('portal.quotes', ['portalKey' => $this->key]))->assertOk()->assertSee($quote->title());
    $this->get($show)->assertOk();

    // Changed after the approval: hidden again, though it stays sent.
    $changed = pf7Quote('85,00', $quote->fresh());

    $this->get($show)->assertNotFound();
    expect($changed->fresh()->sent_at)->not->toBeNull();
});

it('shows a sent quote without a typed price as before', function () {
    $quote = CurrentCompany::run($this->company, fn (): SalesDocument => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id, 'document_date' => '2026-03-15', 'until_date' => '2026-04-15',
        'rows' => [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'vat_rate' => 2500]],
    ]));
    $quote->forceFill(['sent_at' => now()])->save();
    Portal::signIn($this, $this->contact);

    $this->get(route('portal.quotes.show', ['portalKey' => $this->key, 'document' => $quote->id]))->assertOk();
});

it('finds the held documents in the same number of queries for 1 and for 30 quotes', function () {
    $count = function (int $quotes): int {
        $ids = [];

        foreach (range(1, $quotes) as $i) {
            $ids[] = pf7Quote('90,00')->id;
        }

        $documents = SalesDocument::query()->whereIn('id', $ids)->get();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $held = PriceFloorGuard::heldIds($this->company, $documents);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($held)->toHaveCount($quotes);

        return $queries;
    };

    $short = $count(1);

    expect($count(30))->toBe($short);
});
