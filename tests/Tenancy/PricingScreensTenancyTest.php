<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Livewire\Pricing\ArticlePrices;
use App\Livewire\Pricing\CustomerPrices;
use App\Livewire\Pricing\PriceListItems;
use App\Livewire\Pricing\PriceLists;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the price screens (ADR-082 P4): company A never reaches
 * company B's list, item, customer or article through a page, a component or
 * a form; another company's id is a 404, not a 403.
 */

beforeEach(function () {
    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();

        CurrentCompany::run($company, function () use ($owner, $company, $side): void {
            $article = app(SaveArticle::class)($owner, $company, ['number' => "H-{$side}", 'name' => "Hemlig vara {$side}", 'unit' => 'st', 'price' => '149', 'vat_rate' => 2500]);
            $list = PriceList::create(['code' => 'HEMLIG', 'name' => "Hemlig lista {$side}", 'created_by' => $owner->id]);
            $item = PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $article->id, 'price_minor' => 12_300]);
            $customer = Crm::customer($company, $owner, "Hemlig kund {$side}");

            $this->{$side} = compact('owner', 'company', 'article', 'list', 'item', 'customer');
        });
    }

    CurrentCompany::forget();
    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('gives 404 for another company\'s list page and lists only its own', function () {
    $this->get("/prislistor/{$this->b['list']->id}")->assertNotFound();
    $this->get("/prislistor/{$this->a['list']->id}")->assertOk();

    Livewire::test(PriceLists::class)->assertSee('Hemlig lista a')->assertDontSee('Hemlig lista b');
});

it('refuses another company\'s list, item, customer and article in the components', function () {
    Livewire::test(PriceListItems::class, ['listId' => $this->b['list']->id])->assertNotFound();
    Livewire::test(CustomerPrices::class, ['customerId' => $this->b['customer']->id])->assertNotFound();
    Livewire::test(ArticlePrices::class, ['articleId' => $this->b['article']->id])->assertNotFound();

    Livewire::test(PriceListItems::class, ['listId' => $this->a['list']->id])->call('edit', $this->b['item']->id)->assertNotFound();
    Livewire::test(PriceListItems::class, ['listId' => $this->a['list']->id])->call('retire', $this->b['item']->id)->assertNotFound();
    Livewire::test(PriceLists::class)->call('edit', $this->b['list']->id)->assertNotFound();
    Livewire::test(PriceLists::class)->call('archive', $this->b['list']->id)->assertNotFound();

    expect($this->b['list']->fresh()->archived_at)->toBeNull()
        ->and($this->b['item']->fresh()->valid_to)->toBeNull();
});

it('does not let a form post another company\'s article or base list', function () {
    Livewire::test(PriceListItems::class, ['listId' => $this->a['list']->id])
        ->call('create')
        ->set('form.article_id', (string) $this->b['article']->id)
        ->set('form.price', '1')
        ->call('save')
        ->assertHasErrors(['article_id']);

    Livewire::test(PriceLists::class)
        ->call('create')
        ->set('form.code', 'NY')
        ->set('form.name', 'Ny')
        ->set('form.derived', true)
        ->set('form.derived_percent', '5')
        ->set('form.based_on_list_id', (string) $this->b['list']->id)
        ->call('save')
        ->assertHasErrors(['based_on_list_id']);

    expect(PriceListItem::query()->count())->toBe(1);
});
