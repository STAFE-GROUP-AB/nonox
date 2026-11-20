<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\SaveCustomerPriceGroup;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Livewire\Invoicing\Articles;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Pricing\ArticlePrices;
use App\Livewire\Pricing\CustomerPrices;
use App\Livewire\Pricing\PriceGroups;
use App\Livewire\Pricing\PriceListItems;
use App\Livewire\Pricing\PriceLists;
use App\Models\Article;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * ADR-082 P4: the price screens. Thin Livewire components over the actions:
 * Swedish toasts on every change, filters in the address, a fixed number of
 * queries however many rows are shown, and the same permissions as the
 * policies (prices:view to see, price-lists:manage to change).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->makeList = fn (string $code, array $extra = []): PriceList => app(SavePriceList::class)($this->owner, $this->company, [
        'code' => $code, 'name' => "Lista {$code}", 'kind' => 'STANDARD', 'currency' => 'SEK', ...$extra,
    ]);
    $this->makeItem = fn (PriceList $list, Article $article, array $extra = []) => app(SavePriceListItem::class)($this->owner, $this->company, $list, [
        'article_id' => $article->id, 'mode' => 'price', 'price' => '90', ...$extra,
    ]);
});

it('shows the three pages to someone who can see prices and a form only to someone who can change them', function () {
    $list = ($this->makeList)('ATER');

    $this->get('/prislistor')->assertOk()->assertSee('Lista ATER')->assertSee('Ny prislista');
    $this->get('/prislistor/grupper')->assertOk()->assertSee('Inga kundgrupper ännu');
    $this->get("/prislistor/{$list->id}")->assertOk()->assertSee('Nytt pris');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor)->get('/prislistor')->assertOk()->assertSee('Lista ATER')->assertDontSee('Ny prislista');
    $this->get("/prislistor/{$list->id}")->assertOk()->assertDontSee('Nytt pris');
});

it('refuses the pages to someone without prices:view and keeps Prislistor out of their menu', function () {
    $outsider = Bookkeeping::member($this->company, 'approver');

    $this->actingAs($outsider)->get('/prislistor')->assertForbidden();
    $this->get('/prislistor/grupper')->assertForbidden();

    $this->actingAs($this->owner)->get('/artiklar')->assertSee('Prislistor');
});

it('makes a list from the form and tells the person with a toast', function () {
    Livewire::test(PriceLists::class)
        ->call('create')
        ->set('form.code', 'sommar')
        ->set('form.name', 'Sommarkampanj')
        ->set('form.kind', 'CAMPAIGN')
        ->set('form.valid_from', '2026-06-01')
        ->set('form.valid_to', '2026-08-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Prislistan Sommarkampanj är tillagd. Lägg in priserna på listans sida.', type: 'success')
        ->assertSet('showForm', false);

    expect(PriceList::query()->where('code', 'SOMMAR')->value('name'))->toBe('Sommarkampanj');
});

it('shows validation errors in the form and keeps it open', function () {
    ($this->makeList)('ATER');

    Livewire::test(PriceLists::class)
        ->call('create')
        ->set('form.code', 'ATER')
        ->set('form.name', 'Dubblett')
        ->call('save')
        ->assertHasErrors(['code'])
        ->assertSet('showForm', true);
});

it('filters and sorts lists through the address and hides archived ones until asked', function () {
    ($this->makeList)('AAA');
    ($this->makeList)('BBB', ['kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']);
    $old = ($this->makeList)('CCC');
    $old->update(['archived_at' => now()]);

    Livewire::test(PriceLists::class)->set('kind', 'CAMPAIGN')->assertSee('Lista BBB')->assertDontSee('Lista AAA');
    Livewire::test(PriceLists::class)->set('search', 'aaa')->assertSee('Lista AAA')->assertDontSee('Lista BBB');
    Livewire::test(PriceLists::class)->assertDontSee('Lista CCC')->set('archived', true)->assertSee('Lista CCC')->assertDontSee('Lista AAA');
    Livewire::test(PriceLists::class)->call('sortBy', 'code')->assertSet('direction', 'desc')->assertSeeInOrder(['Lista BBB', 'Lista AAA']);
    Livewire::test(PriceLists::class)->call('sortBy', 'password; drop')->assertSet('sort', 'code');
});

it('archives and restores a list with a toast, and explains why a used list stays', function () {
    $list = ($this->makeList)('ATER');
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'Grupp', 'price_list_id' => $list->id]);

    Livewire::test(PriceLists::class)
        ->call('archive', $list->id)
        ->assertDispatched('toast', type: 'error');

    $group->update(['price_list_id' => null]);

    Livewire::test(PriceLists::class)
        ->call('archive', $list->id)
        ->assertDispatched('toast', message: 'Prislistan är arkiverad.', type: 'success')
        ->call('restore', $list->id)
        ->assertDispatched('toast', message: 'Prislistan är återställd.', type: 'success');
});

it('runs the same number of queries for three lists as for thirty', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PriceLists::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (['A', 'B', 'C'] as $code) {
        $list = ($this->makeList)($code, ['kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']);
        ($this->makeItem)($list, $this->article);
    }

    $short = $count();

    for ($i = 0; $i < 12; $i++) {
        $base = ($this->makeList)("X{$i}");
        ($this->makeList)("Y{$i}", ['derived' => true, 'derived_percent' => '-5', 'based_on_list_id' => $base->id]);
        $campaign = ($this->makeList)("K{$i}", ['kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'group_ids' => [app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => "G{$i}", 'name' => "G{$i}"])->id]]);
        ($this->makeItem)($campaign, $this->article);
    }

    expect($count())->toBe($short);
});

it('adds, changes and retires prices on a list page with toasts', function () {
    $list = ($this->makeList)('ATER');

    $page = Livewire::test(PriceListItems::class, ['listId' => $list->id])
        ->call('create')
        ->set('articleSearch', 'skr')
        ->assertSee('Skruv')
        ->call('chooseArticle', $this->article->id)
        ->set('form.price', '89,50')
        ->set('form.min_quantity', '10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Priset är tillagt.', type: 'success')
        ->assertSee('89,50');

    $item = PriceListItem::query()->where('price_list_id', $list->id)->firstOrFail();

    expect($item->price_minor)->toBe(8950)->and($item->min_quantity_milli)->toBe(10000);

    $page->call('edit', $item->id)
        ->set('form.mode', 'discount')
        ->set('form.discount', '12,5')
        ->call('save')
        ->assertDispatched('toast', message: 'Priset är sparat.', type: 'success');

    expect($item->fresh()->discount_bp)->toBe(1250)->and($item->fresh()->price_minor)->toBeNull();

    $item->update(['valid_from' => '2026-01-01']);

    $page->call('retire', $item->id)
        ->assertDispatched('toast', message: 'Priset gäller till och med i går och finns kvar i historiken.', type: 'success');

    expect($item->fresh()->valid_to->toDateString())->toBe('2026-06-14');
});

it('shows the overlap refusal on the field and lets a new price follow a running one', function () {
    $list = ($this->makeList)('ATER');
    $running = ($this->makeItem)($list, $this->article, ['valid_from' => '2026-01-01']);

    Livewire::test(PriceListItems::class, ['listId' => $list->id])
        ->call('create')
        ->call('chooseArticle', $this->article->id)
        ->set('form.price', '80')
        ->call('save')
        ->assertHasErrors(['valid_from']);

    Livewire::test(PriceListItems::class, ['listId' => $list->id])
        ->call('raise', $running->id)
        ->set('form.price', '95')
        ->set('form.valid_from', '2026-07-01')
        ->call('save')
        ->assertHasNoErrors();

    expect($running->fresh()->valid_to->toDateString())->toBe('2026-06-30');
});

it('filters prices by status and search and runs the same queries for few and many', function () {
    $list = ($this->makeList)('ATER');
    $count = function () use ($list): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PriceListItems::class, ['listId' => $list->id])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    ($this->makeItem)($list, $this->article, ['valid_from' => '2026-01-01', 'valid_to' => '2026-02-01']);
    ($this->makeItem)($list, $this->article, ['valid_from' => '2026-09-01']);

    Livewire::test(PriceListItems::class, ['listId' => $list->id])->assertSee('Kommande')->assertDontSee('2026-02-01')
        ->set('show', 'avslutade')->assertSee('2026-02-01')->assertDontSee('Kommande')
        ->set('show', 'alla')->assertSee('Kommande')->assertSee('2026-02-01')
        ->set('search', 'finns inte')->assertSee('Inga priser matchar');

    $short = $count();

    for ($i = 0; $i < 20; $i++) {
        $article = app(SaveArticle::class)($this->owner, $this->company, ['number' => "B-{$i}", 'name' => "Vara {$i}", 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500]);
        ($this->makeItem)($list, $article);
    }

    expect($count())->toBe($short);
});

it('makes and archives customer groups with toasts', function () {
    $list = ($this->makeList)('ATER');

    Livewire::test(PriceGroups::class)
        ->call('create')
        ->set('form.code', 'ater')
        ->set('form.name', 'Återförsäljare')
        ->set('form.price_list_id', (string) $list->id)
        ->call('save')
        ->assertDispatched('toast', message: 'Kundgruppen Återförsäljare är tillagd.', type: 'success')
        ->assertSee('Återförsäljare');

    $group = CustomerPriceGroup::query()->firstOrFail();

    Livewire::test(PriceGroups::class)
        ->call('archive', $group->id)
        ->assertDispatched('toast', message: 'Kundgruppen är arkiverad.', type: 'success');
});

it('shows the customer\'s group, own list and today\'s resolved prices on the customer tab', function () {
    $list = ($this->makeList)('ATER');
    ($this->makeItem)($list, $this->article, ['price' => '80']);
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'Grupp', 'price_list_id' => $list->id]);
    $customer = Crm::customer($this->company, $this->owner, 'Kund AB');

    Livewire::test(CustomerPrices::class, ['customerId' => $customer->id])
        ->assertSee('100,00')
        ->assertSee('Grundpris')
        ->set('groupId', (string) $group->id)
        ->call('save')
        ->assertDispatched('toast', message: 'Kundens priser är sparade.', type: 'success')
        ->assertSee('80,00')
        ->assertSee('Kundens prislista');

    expect($customer->fresh()->price_group_id)->toBe($group->id);
});

it('prices the customer preview in a fixed number of queries', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kund AB');
    $count = function () use ($customer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CustomerPrices::class, ['customerId' => $customer->id])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count();
    $list = ($this->makeList)('ATER', ['is_default_for_currency' => true]);

    for ($i = 0; $i < 12; $i++) {
        $article = app(SaveArticle::class)($this->owner, $this->company, ['number' => "B-{$i}", 'name' => "Vara {$i}", 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500]);
        ($this->makeItem)($list, $article);
    }

    expect($count() - $short)->toBeLessThanOrEqual(1);
});

it('shows the article\'s base price history, where it is priced and the price on a date', function () {
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '120', 'vat_rate' => 2500], $this->article);
    $list = ($this->makeList)('ATER');
    ($this->makeItem)($list, $this->article, ['price' => '95', 'min_quantity' => '10']);
    ($this->makeList)('MINUS', ['derived' => true, 'derived_percent' => '-10']);

    Livewire::test(ArticlePrices::class, ['articleId' => $this->article->id])
        ->assertSee('120,00')
        ->assertSee('Artikelformuläret')
        ->assertSee('Lista ATER')
        ->assertSee('95,00')
        ->assertSee('följer -10 %')
        ->set('quantity', '10')
        ->assertSee('Grundpris')
        ->set('date', 'inte ett datum')
        ->assertSee('Skriv ett datum och ett antal');
});

it('adds the tab Priser to the article and customer forms', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kund AB');

    Livewire::test(Articles::class)->call('edit', $this->article->id)->assertSee('Priser')->set('tab', 'priser')->assertSeeLivewire(ArticlePrices::class);
    Livewire::test(Customers::class)->call('edit', $customer->id)->assertSee('Priser')->set('tab', 'priser')->assertSeeLivewire(CustomerPrices::class);
    Livewire::test(Customers::class)->call('edit', $customer->id)->set('tab', 'priser')->call('cancel')->assertSet('tab', 'uppgifter');
});
