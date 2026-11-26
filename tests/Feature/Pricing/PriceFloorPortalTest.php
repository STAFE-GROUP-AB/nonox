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

it('checks only the quotes on the page: the portal list costs the same queries for a short and a long history', function () {
    Portal::signIn($this, $this->contact);

    // Odd quotes are priced at 100 kr (clear of the floor), even ones at 90 kr (held back).
    $measure = function (int $quotes): array {
        foreach (range(1, $quotes) as $i) {
            pf7Quote($i % 2 === 1 ? '100,00' : '90,00')->forceFill(['sent_at' => now()])->save();
        }

        $this->get(route('portal.quotes', ['portalKey' => $this->key]))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('portal.quotes', ['portalKey' => $this->key]))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $rowQueries = array_values(array_filter($log, fn (array $query): bool => str_contains($query['query'], 'sales_document_rows')));

        expect($rowQueries)->not->toBeEmpty();

        return [count($log), count($rowQueries[0]['bindings']), $response->viewData('documents')->count()];
    };

    [$shortQueries, , $shortShown] = $measure(3);
    [$longQueries, $rowIds, $longShown] = $measure(40);

    // The newest 25 of the 40 are quotes 16 to 40; the odd ones among them are shown.
    expect($longQueries)->toBe($shortQueries)
        ->and($rowIds)->toBeLessThanOrEqual(26)
        ->and($shortShown)->toBe(2)
        ->and($longShown)->toBe(12);
});

it('keeps the later pages reachable when every quote on a page is held', function () {
    Portal::signIn($this, $this->contact);

    foreach (range(1, 26) as $i) {
        pf7Quote('90,00')->forceFill(['sent_at' => now()])->save();
    }

    $this->get(route('portal.quotes', ['portalKey' => $this->key]))
        ->assertOk()
        ->assertSee('Inga offerter eller ordrar att visa på den här sidan')
        ->assertDontSee('Inga offerter eller ordrar ännu')
        ->assertSee('page=2', false);

    $this->get(route('portal.quotes', ['portalKey' => $this->key]).'?page=2')->assertOk()->assertSee('Inga offerter eller ordrar att visa på den här sidan');
});

it('leaves a held quote out of the open quotes on the overview', function () {
    Portal::signIn($this, $this->contact);
    pf7Quote('90,00')->forceFill(['sent_at' => now()])->save();
    pf7Quote('100,00')->forceFill(['sent_at' => now()])->save();

    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertOk()->assertViewHas('openQuotes', 1);
});
