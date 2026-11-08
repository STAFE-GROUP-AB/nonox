<?php

declare(strict_types=1);

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\CreateQuoteForDeal;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\QuoteFromTemplate;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * A quote made from a deal is linked to it, and the order and invoice that
 * follow are linked too, so winning the deal needs no manual pick.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $this->deal = Crm::deal($this->company, $this->owner, Crm::accountFor($this->customer), 'Webbprojekt', 1_250_000);
});

function dealTemplate(object $test): QuoteTemplate
{
    return app(SaveQuoteTemplate::class)($test->owner, $test->company, ['name' => 'Mall', 'validity_days' => 14, 'rows' => [['description' => 'Uppstart', 'quantity' => '1', 'unit' => 'st', 'price' => '2 500', 'vat_rate' => 2500]]]);
}

it('makes a one-row quote from the deal and links it', function () {
    $quote = app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal);
    $row = $quote->rows()->get()->sole();

    expect($this->deal->refresh()->quote_id)->toBe($quote->id)
        ->and($quote->customer_id)->toBe($this->customer->id)
        ->and($row->description)->toBe('Webbprojekt')
        ->and($row->unit_price_ore->ore)->toBe(1_250_000)
        ->and($row->vat_rate)->toBe(2500)
        ->and($quote->net_ore->ore)->toBe(1_250_000);
});

it('links a quote made from a template too', function () {
    $quote = app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal, dealTemplate($this));

    expect($this->deal->refresh()->quote_id)->toBe($quote->id)
        ->and($quote->rows()->get()->sole()->description)->toBe('Uppstart');
});

it('makes a prospect a customer first, and refuses when the user may not add customers', function () {
    $prospect = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $deal = Crm::deal($this->company, $this->owner, $prospect, 'Nytt');

    $quote = app(CreateQuoteForDeal::class)($this->owner, $this->company, $deal);

    expect($prospect->refresh()->customer_id)->not->toBeNull()->and($quote->customer_id)->toBe($prospect->customer_id);

    $other = Crm::prospect($this->company, $this->owner, 'Annan AB');
    $otherDeal = Crm::deal($this->company, $this->owner, $other, 'Annat');
    $approver = Bookkeeping::member($this->company, 'approver');
    expect(fn () => app(CreateQuoteForDeal::class)($approver, $this->company, $otherDeal))->toThrow(AuthorizationException::class);
});

it('refuses a closed deal', function () {
    app(WinDeal::class)($this->owner, $this->company, $this->deal);

    expect(fn () => app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal->refresh()))->toThrow(ValidationException::class, 'avslutad');
});

it('lets the order and the invoice follow the quote onto the deal, and preselects them when winning', function () {
    $quote = app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal);

    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);
    expect($this->deal->refresh()->order_id)->toBe($order->id)->and($this->deal->invoice_id)->toBeNull();

    $invoice = app(ProgressSalesDocument::class)->toInvoice($this->owner, $order->refresh());
    expect($this->deal->refresh()->invoice_id)->toBe($invoice->id);

    Livewire::test(DealCard::class, ['dealId' => $this->deal->id])
        ->call('startWin')
        ->assertSet('win.quote_id', $quote->id)
        ->assertSet('win.order_id', $order->id)
        ->assertSet('win.invoice_id', $invoice->id);
});

it('follows a quote that goes straight to an invoice', function () {
    $quote = app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal);

    $invoice = app(ProgressSalesDocument::class)->toInvoice($this->owner, $quote);

    expect($this->deal->refresh()->invoice_id)->toBe($invoice->id)->and($this->deal->order_id)->toBeNull();
});

it('does not touch a deal when an unlinked quote is progressed', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id, 'document_date' => '2026-06-15',
        'rows' => [['description' => 'X', 'quantity' => '1', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]],
    ]);

    app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);

    expect($this->deal->refresh()->order_id)->toBeNull();
});

it('offers Ny offert on the deal card and opens the quote in the editor', function () {
    $component = Livewire::test(DealCard::class, ['dealId' => $this->deal->id])->assertSee('Ny offert')->call('newQuote');
    $quote = SalesDocument::query()->get()->sole();

    $component->assertRedirect(route('invoicing.quotes.show', ['document' => $quote->id]));
    expect($this->deal->refresh()->quote_id)->toBe($quote->id);
});

it('shows Ny offert only with invoices:create', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'approver'));

    Livewire::test(DealCard::class, ['dealId' => $this->deal->id])->assertDontSee('Ny offert');
});

it('links the deal from the template picker on the deal card', function () {
    Livewire::test(QuoteFromTemplate::class, ['dealId' => $this->deal->id])
        ->set('templateId', (string) dealTemplate($this)->id)->call('create');

    expect($this->deal->refresh()->quote_id)->not->toBeNull();
});

it('keeps the deal card at a fixed number of queries', function () {
    $count = function (): int {
        Livewire::test(DealCard::class, ['dealId' => $this->deal->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DealCard::class, ['dealId' => $this->deal->id]);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $before = $count();
    app(CreateQuoteForDeal::class)($this->owner, $this->company, $this->deal);
    Crm::deal($this->company, $this->owner, Crm::accountFor($this->customer), 'Annan');

    expect($count())->toBeLessThanOrEqual($before + 2);
});

it('never uses a deal of another company', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $this->actingAs($otherOwner);
    Crm::enable($otherCompany, $otherOwner);
    $foreign = Crm::deal($otherCompany, $otherOwner, Crm::accountFor(Crm::customer($otherCompany, $otherOwner, 'Främmande AB')), 'Främmande');
    $template = dealTemplate((object) ['owner' => $otherOwner, 'company' => $otherCompany]);

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::test(DealCard::class, ['dealId' => $foreign->id])->assertNotFound();
    Livewire::test(QuoteFromTemplate::class, ['dealId' => $foreign->id])->set('templateId', (string) $template->id)->call('create')->assertNotFound();
    expect(SalesDocument::query()->count())->toBe(0);
});
