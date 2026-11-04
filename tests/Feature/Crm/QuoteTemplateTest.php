<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\QuoteFromTemplate;
use App\Modules\Crm\Livewire\QuoteTemplatesPage;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Quote templates (ADR-046): article rows priced from the article register
 * on the day a quote is made, free rows with their own price, intro and
 * terms as the quote's message, a validity in days. invoices:create.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'K1', 'name' => 'Konsulttimme', 'unit' => 'tim', 'price' => '1000,00', 'vat_rate' => 2500]);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
});

function template(object $test): QuoteTemplate
{
    return app(SaveQuoteTemplate::class)($test->owner, $test->company, [
        'name' => 'Webbprojekt',
        'intro_text' => 'Tack för att vi fick lämna offert.',
        'terms_text' => 'Betalning 30 dagar netto.',
        'validity_days' => 14,
        'rows' => [
            ['article' => 'K1', 'quantity' => '10', 'discount' => '10'],
            ['description' => 'Uppstart', 'quantity' => '1', 'unit' => 'st', 'price' => '2 500', 'vat_rate' => 2500],
        ],
    ]);
}

it('makes a quote from a template at today\'s article price, with the texts and the validity', function () {
    $template = template($this);
    $this->article->forceFill(['price_ore' => 120000])->save();

    $quote = app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template, $this->customer->id);
    $rows = $quote->rows()->get();

    expect($quote->kind)->toBe(SalesDocument::QUOTE)
        ->and($quote->until_date?->toDateString())->toBe('2026-06-29')
        ->and($quote->notes)->toBe("Tack för att vi fick lämna offert.\n\nBetalning 30 dagar netto.")
        ->and($rows[0]->description)->toBe('Konsulttimme')
        ->and($rows[0]->unit)->toBe('tim')
        ->and($rows[0]->unit_price_ore->ore)->toBe(120000)
        ->and($rows[0]->discount_bp)->toBe(1000)
        ->and($rows[0]->net_ore->ore)->toBe(1_080_000)
        ->and($rows[1]->unit_price_ore->ore)->toBe(250000)
        ->and($quote->net_ore->ore)->toBe(1_330_000);
});

it('refuses an inactive article, a row without article or text, and a quote from an archived template or article', function () {
    expect(fn () => app(SaveQuoteTemplate::class)($this->owner, $this->company, ['name' => 'X', 'validity_days' => 30, 'rows' => [['article' => 'FINNS-EJ']]]))->toThrow(ValidationException::class, 'artikeln finns inte')
        ->and(fn () => app(SaveQuoteTemplate::class)($this->owner, $this->company, ['name' => 'X', 'validity_days' => 30, 'rows' => [['price' => '10']]]))->toThrow(ValidationException::class, 'benämning');

    $template = template($this);
    Article::query()->whereKey($this->article->id)->update(['is_active' => false]);
    expect(fn () => app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template, $this->customer->id))->toThrow(ValidationException::class, 'inte längre');

    app(SaveQuoteTemplate::class)->archive($this->owner, $this->company, $template);
    expect(fn () => app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template->refresh(), $this->customer->id))->toThrow(ValidationException::class, 'arkiverad');
});

it('needs invoices:create: a seller neither manages templates nor sees Ny offert från mall', function () {
    template($this);
    $seller = Bookkeeping::member($this->company, 'sales');
    $this->actingAs($seller);

    $this->get(route('crm.quoteTemplates'))->assertForbidden();
    Livewire::test(QuoteFromTemplate::class, ['customerId' => $this->customer->id])->assertDontSee('Ny offert från mall');
});

it('edits templates on Offertmallar and offers them on Offerter and the account card', function () {
    Livewire::test(QuoteTemplatesPage::class)
        ->call('create')
        ->set('form.name', 'Service')->set('form.validity_days', '30')
        ->set('rows.0.article', 'K1')->set('rows.0.quantity', '2')
        ->call('save')->assertHasNoErrors()->assertSee('Service');

    $this->get(route('invoicing.quotes'))->assertOk()->assertSee('Ny offert från mall');
    Livewire::test(AccountCard::class, ['accountId' => Crm::accountFor($this->customer)->id])->assertSee('Ny offert från mall');

    $template = QuoteTemplate::query()->where('name', 'Service')->sole();
    Livewire::test(QuoteFromTemplate::class)->set('templateId', (string) $template->id)->set('customerId', (string) $this->customer->id)->call('create')
        ->assertRedirect(route('invoicing.quotes.show', SalesDocument::query()->where('kind', 'QUOTE')->latest('number')->firstOrFail()));
});
