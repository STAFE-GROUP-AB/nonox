<?php

declare(strict_types=1);

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Pricing\ChannelPrices;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\RecurringPriceMode;
use App\Livewire\Invoicing\AgreementEditor;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InvoiceRow;
use App\Models\PriceListItem;
use App\Models\RecurringInvoice;
use App\Models\Task;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelPrices;
use App\Modules\Pos\Actions\SavePosConnectionSettings;
use App\Modules\Pos\Actions\SyncProducts;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\Pos;
use Tests\Support\PricingScenario;
use Tests\Support\WorkOrders;

/*
 * ADR-082 P8: the other consumers of the price engine. Recurring invoices
 * that follow the price lists, CRM quote templates, work-order material,
 * the till (CHANNEL lists, campaigns, pricing:sync-channels) and shelf labels.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

/** A customer with its own list: A1 costs 80 kr (base price 100 kr), A2 costs 40 kr (base price 50 kr). */
function vipWorld(object $test): PricingScenario
{
    return PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 5000]],
        'lists' => ['VIP' => ['items' => [['article' => 'A1', 'price_minor' => 8000], ['article' => 'A2', 'price_minor' => 4000]]]],
        'customer' => ['list' => 'VIP'],
    ], $test->owner, $test->company);
}

/** @param  list<array<string, mixed>>  $rows */
function agreementFor(object $test, Customer $customer, array $rows, string $mode, string $next = '2026-06-15'): RecurringInvoice
{
    return app(SaveRecurringInvoice::class)($test->owner, $test->company, [
        'customer_id' => $customer->id,
        'name' => 'Abonnemang',
        'interval_months' => 1,
        'next_invoice_date' => $next,
        'price_mode' => $mode,
        'rows' => $rows,
    ]);
}

function articleRow(string $number, string $quantity = '2', string $price = '100,00'): array
{
    return ['article' => $number, 'description' => "Artikel {$number}", 'quantity' => $quantity, 'price' => $price, 'vat_rate' => 2500];
}

it('keeps the written prices on a FIXED agreement, as before', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1')], 'FIXED');

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());

    expect($agreement->price_mode)->toBe(RecurringPriceMode::Fixed)
        ->and($invoice->rows()->sole()->unit_price_ore->ore)->toBe(10000)
        ->and($invoice->rows()->sole()->price_source)->toBeNull();
});

it('defaults to FIXED and keeps the mode when a save does not name one', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1')], 'FOLLOW_PRICES');

    $changed = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Nytt namn', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [articleRow('A1')],
    ], $agreement);

    expect($changed->price_mode)->toBe(RecurringPriceMode::FollowPrices);

    $new = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Utan läge', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [articleRow('A1')],
    ]);

    expect($new->price_mode)->toBe(RecurringPriceMode::Fixed);

    expect(fn () => agreementFor($this, $world->customer, [articleRow('A1')], 'WHENEVER'))->toThrow(ValidationException::class);
});

it('prices a FOLLOW_PRICES agreement on each run date and stores the source and explanation', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [
        articleRow('A1'),
        ['description' => 'Fri rad', 'quantity' => '1', 'price' => '300,00', 'vat_rate' => 2500],
    ], 'FOLLOW_PRICES');

    $first = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());
    $rows = InvoiceRow::query()->where('invoice_id', $first->id)->orderBy('position')->get();

    expect($rows[0]->unit_price_ore->ore)->toBe(8000)
        ->and($rows[0]->price_source)->toBe(PriceSource::CustomerList)
        ->and($rows[0]->price_explanation['list_id'] ?? null)->toBe($world->lists['VIP'])
        ->and($rows[1]->unit_price_ore->ore)->toBe(30000)
        ->and($rows[1]->price_source)->toBeNull();

    // The price list changes; the next run, a month later, follows it. The first invoice keeps its price.
    PriceListItem::query()->whereKey($world->items['VIP#0'])->update(['price_minor' => 7500]);
    $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
    $second = app(RunRecurringInvoice::class)($this->owner, $agreement->fresh(), CarbonImmutable::today());

    expect($second->rows()->orderBy('position')->first()->unit_price_ore->ore)->toBe(7500)
        ->and($first->rows()->orderBy('position')->first()->unit_price_ore->ore)->toBe(8000);
});

it('shows the last run on the agreement\'s own rows and totals', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1')], 'FOLLOW_PRICES');

    app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());
    $agreement->refresh();
    $row = $agreement->rows()->sole();

    expect($row->unit_price_ore->ore)->toBe(8000)
        ->and($row->net_ore->ore)->toBe(16000)
        ->and($row->price_source)->toBe(PriceSource::CustomerList)
        ->and($agreement->net_ore->ore)->toBe(16000);
});

it('prices a followed article at the register\'s base price when its list item is gone', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1')], 'FOLLOW_PRICES');
    Article::query()->where('number', 'A1')->update(['price_ore' => 0]);
    PriceListItem::query()->whereKey($world->items['VIP#0'])->delete();

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());

    // A base price of 0 is a price (BASE); the run still makes the draft, at 0 kr, for the person to check.
    expect($invoice->isDraft())->toBeTrue()
        ->and($invoice->rows()->sole()->price_source)->toBe(PriceSource::Base);
});

it('runs a FOLLOW_PRICES agreement in a fixed number of select queries however many rows it has', function () {
    $world = vipWorld($this);

    $selects = function (int $rows) use ($world): int {
        $articleRows = [];

        foreach (range(1, $rows) as $i) {
            $articleRows[] = articleRow($i % 2 === 0 ? 'A1' : 'A2', (string) $i);
        }

        $agreement = agreementFor($this, $world->customer, $articleRows, 'FOLLOW_PRICES');
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
    };

    expect($selects(12))->toBe($selects(2));
});

it('prices a quote made from a template for the customer, from its price list', function () {
    $world = vipWorld($this);
    Crm::enable($this->company, $this->owner);
    $template = app(SaveQuoteTemplate::class)($this->owner, $this->company, [
        'name' => 'Paket', 'validity_days' => 14,
        'rows' => [['article' => 'A1', 'quantity' => '3'], ['description' => 'Uppstart', 'quantity' => '1', 'unit' => 'st', 'price' => '2 500', 'vat_rate' => 2500]],
    ]);

    $quote = app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template, $world->customer->id);
    $rows = $quote->rows()->orderBy('position')->get();

    expect($rows[0]->unit_price_ore->ore)->toBe(8000)
        ->and($rows[0]->price_source)->toBe(PriceSource::CustomerList)
        ->and($rows[1]->unit_price_ore->ore)->toBe(250000)
        ->and($rows[1]->price_source)->toBeNull();

    $other = Crm::customer($this->company, $this->owner, 'Kund utan lista');
    $plain = app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template, $other->id);

    expect($plain->rows()->orderBy('position')->first()->unit_price_ore->ore)->toBe(10000)
        ->and($plain->rows()->orderBy('position')->first()->price_source)->toBe(PriceSource::Base);
});

it('prices work-order material for the work order\'s customer, and marks a typed price manual', function () {
    WorkOrders::enable($this->company, $this->owner);
    $world = vipWorld($this);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $world->customer);
    $article = Article::query()->where('number', 'A1')->sole();
    $input = ['source' => 'FREE', 'article_id' => $article->id, 'quantity' => '2', 'work_date' => '2026-06-15'];

    $entry = app(RecordMaterial::class)($this->owner, $this->company, $workOrder, $input);

    expect($entry->unit_price_ore)->toBe(8000)
        ->and($entry->price_source)->toBe(PriceSource::CustomerList)
        ->and($entry->price_explanation['list_id'] ?? null)->toBe($world->lists['VIP']);

    $typed = app(RecordMaterial::class)($this->owner, $this->company, $workOrder, [...$input, 'unit_price' => '95,00']);

    expect($typed->unit_price_ore)->toBe(9500)->and($typed->price_source)->toBe(PriceSource::Manual);

    $free = app(RecordMaterial::class)($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'description' => 'Skruv', 'quantity' => '1', 'unit' => 'st', 'unit_cost' => '10,00', 'work_date' => '2026-06-15']);

    expect($free->price_source)->toBeNull();
});

/** A POS channel list, a campaign for the POS channel and a portal list, for tills and labels. */
function channelWorld(object $test): PricingScenario
{
    return PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000]],
        'lists' => [
            'KASSA' => ['kind' => 'CHANNEL', 'channel' => 'POS', 'items' => [['article' => 'A1', 'price_minor' => 9000]]],
            'WEBB' => ['kind' => 'CHANNEL', 'channel' => 'PORTAL', 'items' => [['article' => 'A1', 'price_minor' => 5000]]],
            'KAMP' => ['kind' => 'CAMPAIGN', 'channel' => 'POS', 'valid_from' => '2026-06-10', 'valid_to' => '2026-06-20', 'items' => [['article' => 'A1', 'price_minor' => 8000]]],
        ],
    ], $test->owner, $test->company);
}

it('prices the till from its CHANNEL list and the POS campaigns, gross including VAT', function () {
    $world = channelWorld($this);
    $article = Article::query()->where('number', 'A1')->sole();
    $gross = fn (?int $list, string $date): ?int => app(ChannelPrices::class)->gross([$article], $list, CarbonImmutable::parse($date))[$article->id] ?? null;

    expect($gross(null, '2026-06-05'))->toBe(12500)
        ->and($gross($world->lists['KASSA'], '2026-06-05'))->toBe(11250)
        ->and($gross($world->lists['KASSA'], '2026-06-15'))->toBe(10000)
        ->and($gross(null, '2026-06-15'))->toBe(10000)
        ->and($gross($world->lists['KASSA'], '2026-06-21'))->toBe(11250)
        // A list of another channel is not the till's.
        ->and($gross($world->lists['WEBB'], '2026-06-05'))->toBe(12500);
});

it('ignores a named list that is not a valid channel list of the right kind', function () {
    $world = channelWorld($this);
    $article = Article::query()->where('number', 'A1')->sole();
    $context = new PriceContext($this->company->id, '2026-06-05', 'SEK', null, PriceChannel::Pos, $world->lists['KAMP']);

    $price = app(PriceResolver::class)->resolve($context, new PriceRequest('r', $article->id));

    expect($price->unitPriceMinor)->toBe(10000)->and($price->source)->toBe(PriceSource::Base);
});

it('prices many articles for the till in as many queries as one', function () {
    $world = channelWorld($this);
    $template = Article::query()->where('number', 'A1')->sole();
    $articles = [$template];

    foreach (range(2, 60) as $i) {
        $copy = $template->replicate();
        $copy->number = "A{$i}";
        $copy->price_ore = Money::ofOre(10000 + $i);
        $copy->save();
        $articles[] = $copy;
    }

    $count = function (array $articles) use ($world): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ChannelPrices::class)->gross($articles, $world->lists['KASSA']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($articles))->toBe($count([$template]));
});

it('syncs the channel prices to the till every night, once per change', function () {
    Queue::fake();
    Pos::configure();
    [$owner, $company] = Pos::company();
    CurrentCompany::set($company);
    $world = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000]],
        'lists' => [
            'KASSA' => ['kind' => 'CHANNEL', 'channel' => 'POS', 'items' => [['article' => 'A1', 'price_minor' => 9000]]],
            'KAMP' => ['kind' => 'CAMPAIGN', 'channel' => 'POS', 'valid_from' => '2026-06-10', 'valid_to' => '2026-06-20', 'items' => [['article' => 'A1', 'price_minor' => 8000]]],
        ],
    ], $owner, $company);
    $connection = Pos::connection($company, $owner, settings: ['price_list_id' => $world->lists['KASSA']]);
    Http::fake(['products.izettle.com/*' => Http::response([], 201)]);
    $amounts = fn (): array => Http::recorded()->map(fn (array $pair): int => $pair[0]['variants'][0]['price']['amount'])->values()->all();

    // Before the campaign: the list's price.
    $this->travelTo(CarbonImmutable::parse('2026-06-05 00:06:00'));
    $this->artisan('pricing:sync-channels')->assertSuccessful();
    $this->artisan('pricing:sync-channels')->assertSuccessful();
    expect($amounts())->toBe([11250]);

    // The campaign starts: the new price goes out once.
    $this->travelTo(CarbonImmutable::parse('2026-06-10 00:06:00'));
    $this->artisan('pricing:sync-channels')->assertSuccessful();
    $this->artisan('pricing:sync-channels')->assertSuccessful();
    expect($amounts())->toBe([11250, 10000]);

    // The campaign has ended: back to the list's price.
    $this->travelTo(CarbonImmutable::parse('2026-06-21 00:06:00'));
    $this->artisan('pricing:sync-channels')->assertSuccessful();
    expect($amounts())->toBe([11250, 10000, 11250])
        ->and(PosProductLink::query()->count())->toBe(1)
        ->and($connection->fresh()->priceListId())->toBe($world->lists['KASSA']);
});

it('sends a till its channel prices in as many price queries for many articles as for one', function () {
    Queue::fake();
    Pos::configure();
    [$owner, $company] = Pos::company();
    CurrentCompany::set($company);
    $world = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000]],
        'lists' => ['KASSA' => ['kind' => 'CHANNEL', 'channel' => 'POS', 'items' => [['article' => 'A1', 'price_minor' => 9000]]]],
    ], $owner, $company);
    Http::fake(['products.izettle.com/*' => Http::response([], 201)]);

    $priceQueries = function () use ($company, $owner, $world): int {
        $connection = Pos::connection($company, $owner, settings: ['price_list_id' => $world->lists['KASSA']]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SyncProducts::class)($connection);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn (array $query): bool => preg_match('/from "(price_lists|price_list_items|price_agreements)"/', $query['query']) === 1));
    };

    $one = $priceQueries();
    $template = Article::query()->where('number', 'A1')->sole();

    foreach (range(2, 40) as $i) {
        $copy = $template->replicate();
        $copy->number = "A{$i}";
        $copy->save();
    }

    expect($priceQueries())->toBe($one)->and($one)->toBeGreaterThan(0);
});

it('lets a till name only an active SEK CHANNEL list for the POS channel', function () {
    Queue::fake();
    Pos::configure();
    [$owner, $company] = Pos::company();
    CurrentCompany::set($company);
    $world = channelWorld((object) ['owner' => $owner, 'company' => $company]);
    $connection = Pos::connection($company, $owner);
    $save = fn (?int $list) => app(SavePosConnectionSettings::class)($owner, $company, $connection, ['sync_direction' => 'ONE_WAY', 'push_products' => true, 'push_stock' => true, 'location_id' => null, 'price_list_id' => $list]);

    expect($save($world->lists['KASSA'])->priceListId())->toBe($world->lists['KASSA'])
        ->and(fn () => $save($world->lists['WEBB']))->toThrow(ValidationException::class)
        ->and(fn () => $save($world->lists['KAMP']))->toThrow(ValidationException::class)
        ->and(fn () => $save(999_999))->toThrow(ValidationException::class)
        ->and($save(null)->priceListId())->toBeNull();
});

it('shows the till\'s price on a shelf label', function () {
    Queue::fake();
    Pos::configure();
    [$owner, $company] = Pos::company();
    CurrentCompany::set($company);
    $world = channelWorld((object) ['owner' => $owner, 'company' => $company]);
    Pos::connection($company, $owner, settings: ['price_list_id' => $world->lists['KASSA']]);
    $article = Article::query()->where('number', 'A1')->sole();

    $prices = LabelPrices::gross([$article]);
    $label = LabelData::forArticle($article, true, [], $prices[$article->id] ?? null);

    // 15 June is inside the campaign: 80 kr plus 25 % VAT.
    expect($label->price?->ore)->toBe(10000);

    $this->travelTo(CarbonImmutable::parse('2026-06-25 10:00:00'));
    $later = LabelData::forArticle($article, true, [], LabelPrices::gross([$article])[$article->id] ?? null);

    expect($later->price?->ore)->toBe(11250);

    $hidden = LabelData::forArticle($article, false, [], 11250);

    expect($hidden->price)->toBeNull();
});

it('lets the agreement editor choose "Följ prislistan" and keeps the article on the rows', function () {
    $world = vipWorld($this);

    Livewire::test(AgreementEditor::class)
        ->set('customerId', (string) $world->customer->id)
        ->set('name', 'Prisföljande')
        ->set('nextInvoiceDate', '2026-06-15')
        ->set('priceMode', 'FOLLOW_PRICES')
        ->set('rows', [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '2', 'unit' => 'st', 'price' => '100,00', 'discount' => '', 'vat_rate' => '2500', 'account' => '']])
        ->call('save')
        ->assertHasNoErrors();

    $agreement = RecurringInvoice::query()->sole();

    expect($agreement->price_mode)->toBe(RecurringPriceMode::FollowPrices)
        ->and($agreement->rows()->sole()->article_id)->not->toBeNull();

    Livewire::test(AgreementEditor::class, ['agreement' => $agreement])
        ->assertSet('priceMode', 'FOLLOW_PRICES')
        ->assertSee('Följ prislistan')
        ->assertSee('Fast pris')
        ->set('name', 'Prisföljande 2')
        ->call('save')
        ->assertHasNoErrors();

    // Saving again from the editor keeps the article, so the next run can still price it.
    expect($agreement->fresh()->rows()->sole()->article_id)->not->toBeNull();
});

it('keeps a followed agreement\'s invoice as a draft, audited, when an article comes out at 0 kr', function () {
    $world = vipWorld($this);
    $customer = $world->customer->forceFill(['email' => 'kund@example.test'])->save() ? $world->customer : $world->customer;
    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'name' => 'Auto', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'price_mode' => 'FOLLOW_PRICES', 'send_automatically' => true,
        'rows' => [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'price' => '', 'vat_rate' => 2500], ['description' => 'Fri', 'quantity' => '1', 'price' => '100,00', 'vat_rate' => 2500]],
    ]);
    Article::query()->where('number', 'A1')->update(['price_ore' => 0]);
    PriceListItem::query()->whereKey($world->items['VIP#0'])->delete();

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());

    expect($invoice->isDraft())->toBeTrue()
        ->and($agreement->fresh()->next_invoice_date->toDateString())->toBe('2026-07-15')
        ->and(AuditLog::query()->where('action', 'agreement.held_as_draft')->exists())->toBeTrue();
});

it('keeps a price typed on a followed agreement\'s article row, as MANUAL, through saves and runs', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1', '1', '95,00'), articleRow('A2', '1', '50,00')], 'FOLLOW_PRICES');
    $rows = $agreement->rows()->get();

    expect($rows[0]->price_source)->toBe(PriceSource::Manual)
        ->and($rows[1]->price_source)->toBeNull();

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());
    $made = $invoice->rows()->orderBy('position')->get();

    expect($made[0]->unit_price_ore->ore)->toBe(9500)
        ->and($made[0]->price_source)->toBe(PriceSource::Manual)
        ->and($made[1]->unit_price_ore->ore)->toBe(4000)
        ->and($agreement->fresh()->rows()->get()[0]->unit_price_ore->ore)->toBe(9500);
});

it('carries a work-order material price and its source onto the invoice row', function () {
    WorkOrders::enable($this->company, $this->owner);
    $world = vipWorld($this);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $world->customer);
    app(RecordMaterial::class)($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'article_id' => Article::query()->where('number', 'A1')->value('id'), 'quantity' => '2', 'work_date' => '2026-06-15']);

    $invoice = app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder, CarbonImmutable::parse('2026-06-15'), CarbonImmutable::parse('2026-06-15'));
    $row = $invoice->rows()->where('description', '!=', '')->get()->first(fn ($r) => $r->article_id !== null);

    expect($row?->unit_price_ore->ore)->toBe(8000)->and($row?->price_source)->toBe(PriceSource::CustomerList);
});

it('keeps a following row that has not run yet as following when the agreement is saved again', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A2', '1', '50,00')], 'FOLLOW_PRICES');
    Article::query()->where('number', 'A2')->update(['price_ore' => 5500]);

    $saved = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Bara nytt namn', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [articleRow('A2', '1', '50,00')],
    ], $agreement);

    expect($saved->rows()->sole()->price_source)->toBeNull();

    // A list price that turns up later is then shown after a run and kept through another save.
    $invoice = app(RunRecurringInvoice::class)($this->owner, $saved->fresh(), CarbonImmutable::today());
    $row = $saved->fresh()->rows()->sole();

    expect($invoice->rows()->sole()->unit_price_ore->ore)->toBe(4000)
        ->and($row->price_source)->toBe(PriceSource::CustomerList);

    $again = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Igen', 'interval_months' => 1, 'next_invoice_date' => '2026-07-15',
        'rows' => [[...articleRow('A2', '1', '40,00'), 'price_source' => 'CUSTOMER_LIST']],
    ], $saved->fresh());

    expect($again->rows()->sole()->price_source)->toBe(PriceSource::CustomerList);
});

it('makes the unchanged rows follow when an agreement is switched from fixed prices', function () {
    $world = vipWorld($this);
    Article::query()->where('number', 'A1')->update(['price_ore' => 12000]);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1', '1', '100,00')], 'FIXED');

    $switched = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Byter', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'price_mode' => 'FOLLOW_PRICES', 'rows' => [articleRow('A1', '1', '100,00')],
    ], $agreement);
    $invoice = app(RunRecurringInvoice::class)($this->owner, $switched->fresh(), CarbonImmutable::today());

    expect($invoice->rows()->sole()->unit_price_ore->ore)->toBe(8000);

    // A row whose price is changed in the same edit keeps the typed price.
    $fixed = agreementFor($this, $world->customer, [articleRow('A1', '1', '100,00')], 'FIXED');
    $typed = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Byter med pris', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'price_mode' => 'FOLLOW_PRICES', 'rows' => [articleRow('A1', '1', '95,00')],
    ], $fixed);

    expect($typed->rows()->sole()->price_source)->toBe(PriceSource::Manual);

    Livewire::test(AgreementEditor::class, ['agreement' => agreementFor($this, $world->customer, [articleRow('A1', '1', '100,00')], 'FIXED')])
        ->set('priceMode', 'FOLLOW_PRICES')
        ->assertSee('Du byter till Följ prislistan');
});

it('makes a held draft visible as a task, once, also for a 100 % discount', function () {
    $world = vipWorld($this);
    $customer = $world->customer;
    $customer->forceFill(['email' => 'kund@example.test'])->save();
    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'name' => 'Rabatt', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'price_mode' => 'FOLLOW_PRICES', 'send_automatically' => true,
        'rows' => [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'price' => '', 'discount' => '100', 'vat_rate' => 2500]],
    ]);

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement, CarbonImmutable::today());
    $task = Task::query()->where('subject_type', 'invoice')->sole();

    expect($invoice->isDraft())->toBeTrue()
        ->and($task->subject_id)->toBe((string) $invoice->id)
        ->and($task->assignee_id)->toBe($this->owner->id)
        ->and($task->title)->toContain((string) $agreement->number);
});

it('warns in the daily run for every draft held back, and counts it', function () {
    $world = vipWorld($this);
    $world->customer->forceFill(['email' => 'kund@example.test'])->save();
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Noll', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'price_mode' => 'FOLLOW_PRICES', 'send_automatically' => true,
        'rows' => [['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'price' => '', 'discount' => '100', 'vat_rate' => 2500]],
    ]);

    $this->artisan('invoices:run-agreements')
        ->expectsOutputToContain('en artikelrad blev 0 kr')
        ->expectsOutputToContain('1 hölls som utkast')
        ->assertSuccessful();
});

it('keeps each row\'s own price source when a re-save has two rows with the same article and price (ADR-082 follow-up)', function () {
    $world = vipWorld($this);
    $manual = [...articleRow('A1', '1', '100,00'), 'price_source' => 'MANUAL'];
    $agreement = agreementFor($this, $world->customer, [$manual, articleRow('A1', '1', '100,00')], 'FOLLOW_PRICES');

    $sources = fn (): array => $agreement->fresh()->rows()->orderBy('position')->get()->map(fn ($row): ?string => $row->price_source?->value)->all();

    expect($sources())->toBe(['MANUAL', null]);

    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [$manual, articleRow('A1', '1', '100,00')],
    ], $agreement);

    expect($sources())->toBe(['MANUAL', null]);

    // The same, with the rows the other way round.
    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [articleRow('A1', '1', '100,00'), $manual],
    ], $agreement);

    expect($sources())->toBe([null, 'MANUAL']);

    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [articleRow('A1', '1', '100,00'), $manual],
    ], $agreement);

    expect($sources())->toBe([null, 'MANUAL']);
});

it('does not hand a deleted row\'s price source to the row that is left (ADR-082 follow-up)', function () {
    $world = vipWorld($this);
    $agreement = agreementFor($this, $world->customer, [articleRow('A1', '1', '100,00'), articleRow('A1', '1', '100,00')], 'FOLLOW_PRICES');
    $first = $agreement->rows()->orderBy('position')->first();
    $first->forceFill(['price_source' => PriceSource::CustomerList->value, 'price_explanation' => ['source' => 'CUSTOMER_LIST', 'list_id' => 1]])->save();
    $second = $agreement->rows()->orderBy('position')->skip(1)->first();

    $save = fn (array $row) => app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $world->customer->id, 'name' => 'Abonnemang', 'interval_months' => 1, 'next_invoice_date' => '2026-06-15',
        'rows' => [$row],
    ], $agreement->fresh());

    // Through the editor: the rows carry their stored ids.
    $editor = Livewire::test(AgreementEditor::class, ['agreement' => $agreement->fresh()]);
    $rows = $editor->get('rows');

    expect($rows[1]['row_id'])->toBe((string) $second->id);

    $editor->set('rows', [$rows[1]])->call('save')->assertHasNoErrors();

    $left = $agreement->fresh()->rows()->sole();

    expect($left->price_source)->toBeNull()
        ->and($left->price_explanation)->toBeNull();

    // A caller that sends no row id: the exact source decides.
    $agreement->rows()->delete();
    $agreement->rows()->create(['position' => 0, 'description' => 'A1', 'quantity' => '1', 'unit_id' => $second->unit_id, 'unit' => $second->unit, 'article_id' => $second->article_id, 'unit_price_ore' => 10000, 'vat_rate' => 2500, 'account_id' => $second->account_id, 'net_ore' => 10000, 'price_source' => PriceSource::CustomerList->value, 'price_explanation' => ['source' => 'CUSTOMER_LIST']]);
    $agreement->rows()->create(['position' => 1, 'description' => 'A1', 'quantity' => '1', 'unit_id' => $second->unit_id, 'unit' => $second->unit, 'article_id' => $second->article_id, 'unit_price_ore' => 10000, 'vat_rate' => 2500, 'account_id' => $second->account_id, 'net_ore' => 10000]);

    $save(articleRow('A1', '1', '100,00'));

    expect($agreement->fresh()->rows()->sole()->price_source)->toBeNull();
});
