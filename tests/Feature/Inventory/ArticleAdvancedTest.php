<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Livewire\Invoicing\Articles;
use App\Models\Article;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * The article register extended for Lager (ADR-039): the advanced fields
 * are additive, the simple form and invoicing keep working, the GTIN check
 * digit is checked, and the stock fields only take effect with Lager on.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
});

it('keeps the simple article working without any advanced field', function () {
    $article = app(SaveArticle::class)($this->owner, $this->company, ['number' => '1', 'name' => 'Lunch', 'unit' => 'st', 'price' => '125', 'vat_rate' => 1200]);

    expect($article->fresh())
        ->vat_treatment->toBe(Article::VAT_STANDARD)
        ->is_stock_item->toBeFalse()
        ->gtin->toBeNull();
});

it('saves the advanced fields and validates the EAN check digit', function () {
    $article = app(SaveArticle::class)($this->owner, $this->company, [
        'number' => 'J-100', 'name' => 'Jacka', 'unit' => 'st', 'price' => '899', 'vat_rate' => 2500,
        'sku' => 'JKT-BLA-M', 'gtin' => '7310865004703', 'scanner_code' => 'X100', 'brand' => 'Nordvik', 'model' => 'Fjäll',
        'description' => 'Begagnad vinterjacka.', 'properties' => [['key' => 'Storlek', 'value' => 'M'], ['key' => '', 'value' => 'ignoreras']],
        'colours' => 'blå, svart', 'materials' => ['ull'], 'weight_grams' => 950, 'length_mm' => 700,
        'purchase_price' => '250,00', 'vat_treatment' => 'VMB', 'supplier_article_number' => 'N-77',
    ]);

    expect($article->fresh())
        ->gtin->toBe('7310865004703')
        ->properties->toBe([['key' => 'Storlek', 'value' => 'M']])
        ->colours->toBe(['blå', 'svart'])
        ->materials->toBe(['ull'])
        ->weight_grams->toBe(950)
        ->purchase_price_ore->toBe(25000)
        ->vat_treatment->toBe('VMB');

    expect(fn () => app(SaveArticle::class)($this->owner, $this->company, ['number' => 'J-101', 'name' => 'Jacka 2', 'unit' => 'st', 'price' => '899', 'vat_rate' => 2500, 'gtin' => '7310865004704']))
        ->toThrow(ValidationException::class, 'Kontrollsiffran i EAN-koden stämmer inte');
    expect(fn () => app(SaveArticle::class)($this->owner, $this->company, ['number' => 'J-102', 'name' => 'Jacka 3', 'unit' => 'st', 'price' => '899', 'vat_rate' => 2500, 'gtin' => '7310865004703']))
        ->toThrow(ValidationException::class, 'EAN-koden används redan');
});

it('shows the tabs and keeps the stock fields until Lager is on', function () {
    Livewire::test(Articles::class)
        ->call('create')
        ->assertSee('Avancerad')
        ->set('tab', 'avancerad')
        ->assertSee('EAN/GTIN')
        ->assertDontSee('Lagerförd')
        ->set('form.number', 'K-1')->set('form.name', 'Kopp')->set('form.price', '99')
        ->set('form.gtin', '96385074')->set('form.is_stock_item', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(Article::query()->where('number', 'K-1')->sole())->gtin->toBe('96385074')->is_stock_item->toBeFalse();

    Inventory::enable($this->company, $this->owner);
    $places = Inventory::locations($this->company, $this->owner);
    $article = Article::query()->where('number', 'K-1')->sole();

    Livewire::test(Articles::class)
        ->call('edit', $article->id)
        ->set('tab', 'avancerad')
        ->set('form.is_stock_item', true)
        ->assertSee('Lagerförd')
        ->set('form.default_location_id', (string) $places['bin1']->id)
        ->set('form.reorder_point', '5')
        ->set('form.reorder_quantity', '20')
        ->set('form.net_realisable_value', '40')
        ->call('save')
        ->assertHasNoErrors();

    expect($article->fresh())
        ->is_stock_item->toBeTrue()
        ->default_location_id->toBe($places['bin1']->id)
        ->reorder_point_milli->toBe(5000)
        ->net_realisable_value_ore->toBe(4000);
});

it('opens the Avancerad tab on an error there', function () {
    Livewire::test(Articles::class)
        ->call('create')
        ->set('form.number', 'K-2')->set('form.name', 'Kopp')->set('form.price', '99')
        ->set('form.gtin', '123')
        ->call('save')
        ->assertHasErrors('gtin')
        ->assertSet('tab', 'avancerad');
});

it('refuses to stop stocking an article that has goods in stock', function () {
    Inventory::enable($this->company, $this->owner);
    $places = Inventory::locations($this->company, $this->owner);
    $article = Inventory::article($this->company, $this->owner);
    Inventory::receive($this->company, $this->owner, $article, $places['bin1'], '1', '80');

    expect(fn () => app(SaveArticle::class)($this->owner, $this->company, ['number' => 'V-1', 'name' => 'Kaffekopp', 'unit' => 'st', 'price' => '199', 'vat_rate' => 2500, 'is_stock_item' => false], $article))
        ->toThrow(ValidationException::class, 'Artikeln har varor i lager');
});

it('lets those who manage the stock change articles, and not Lagerpersonal', function () {
    Inventory::enable($this->company, $this->owner);
    $article = Inventory::article($this->company, $this->owner);
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->company->unsetRelation('users');

    expect($clerk->can('update', $article))->toBeFalse()
        ->and($this->owner->can('update', $article))->toBeTrue();
});
