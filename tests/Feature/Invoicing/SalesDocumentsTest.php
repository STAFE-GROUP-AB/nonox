<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendSalesDocument;
use App\Domain\Invoicing\SalesDocumentPdf;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Livewire\Invoicing\SalesDocuments;
use App\Mail\SalesDocumentMail;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Bröllopsfirman', 'email' => 'brollop@exempel.test', 'payment_terms_days' => 10]);
});

function cateringQuote(User $user, Company $company, Customer $customer): SalesDocument
{
    return app(SaveSalesDocument::class)($user, $company, SalesDocument::QUOTE, [
        'customer_id' => $customer->id,
        'document_date' => '2026-04-01',
        'until_date' => '2026-04-30',
        'rows' => [
            ['description' => 'Middag, tre rätter', 'quantity' => '80', 'price' => '395', 'vat_rate' => 1200],
            ['description' => 'Vinpaket', 'quantity' => '80', 'price' => '180', 'vat_rate' => 2500, 'discount' => '5'],
        ],
    ]);
}

it('numbers quotes and orders separately and calculates them like invoices', function () {
    $quote = cateringQuote($this->owner, $this->company, $this->customer);
    $second = cateringQuote($this->owner, $this->company, $this->customer);
    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, $quote);

    expect([$quote->number, $second->number, $order->number])->toBe([1, 2, 1])
        ->and($quote->total_ore->ore)->toBe(5_249_200)
        ->and($order->total_ore->ore)->toBe($quote->total_ore->ore)
        ->and($order->from_quote_id)->toBe($quote->id)
        ->and($quote->fresh()->status)->toBe(SalesDocumentStatus::Accepted);
});

it('turns an order into a draft invoice row for row, and the invoice books it', function () {
    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, cateringQuote($this->owner, $this->company, $this->customer));

    $draft = app(ProgressSalesDocument::class)->toInvoice($this->owner, $order);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->total_ore->ore)->toBe($order->total_ore->ore)
        ->and($draft->rows->pluck('description')->all())->toBe(['Middag, tre rätter', 'Vinpaket'])
        ->and($draft->rows[1]->discount_bp)->toBe(500)
        ->and($order->fresh()->status)->toBe(SalesDocumentStatus::Invoiced)
        ->and($order->fresh()->invoice_id)->toBe($draft->id);

    $invoice = app(IssueInvoice::class)($this->owner, $draft);
    expect($invoice->voucher->reference())->toBe('B1');
});

it('keeps an article\'s own revenue account', function () {
    $account = Account::create(['number' => 3051, 'name' => 'Catering', 'type' => 'REVENUE']);
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'CAT', 'name' => 'Catering', 'unit' => 'pers', 'price' => '250', 'vat_rate' => 1200, 'account' => 3051]);

    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-04-01',
        'rows' => [['article' => 'CAT', 'description' => 'Catering', 'quantity' => '20', 'price' => '250', 'vat_rate' => 1200]],
    ]);

    expect($quote->rows[0]->account_id)->toBe($account->id)
        ->and($quote->rows[0]->unit)->toBe('pers');
});

it('changes only open documents', function () {
    $quote = cateringQuote($this->owner, $this->company, $this->customer);
    app(ProgressSalesDocument::class)->cancel($this->owner, $quote);

    expect($quote->fresh()->status)->toBe(SalesDocumentStatus::Cancelled)
        ->and(fn () => app(ProgressSalesDocument::class)->toOrder($this->owner, $quote->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-04-01', 'rows' => [['description' => 'X', 'quantity' => '1', 'price' => '1', 'vat_rate' => 2500]]], $quote->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('refuses to make an order out of an order', function () {
    $order = app(ProgressSalesDocument::class)->toOrder($this->owner, cateringQuote($this->owner, $this->company, $this->customer));

    expect(fn () => app(ProgressSalesDocument::class)->toOrder($this->owner, $order))->toThrow(ValidationException::class, 'Bara en offert');
});

it('sends a quote as a PDF', function () {
    Mail::fake();
    $quote = cateringQuote($this->owner, $this->company, $this->customer);

    app(SendSalesDocument::class)($this->owner, $quote, 'brollop@exempel.test');

    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail) => $mail->hasTo('brollop@exempel.test') && $mail->attachments()[0]->as === 'Offert-1.pdf');
    expect(SalesDocumentPdf::render($quote))->toStartWith('%PDF')
        ->and($quote->fresh()->sent_to)->toBe('brollop@exempel.test');
});

describe('in the browser', function () {
    it('writes a quote, sends it, makes an order and an invoice with toasts', function () {
        Mail::fake();

        Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE])
            ->set('customerId', (string) $this->customer->id)
            ->set('rows', [['article' => '', 'description' => 'Smörgåstårta', 'quantity' => '3', 'unit' => 'st', 'price' => '450', 'discount' => '', 'vat_rate' => '1200', 'account' => '']])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $quote = SalesDocument::query()->sole();
        expect(session('toasts')[0]['message'])->toBe('Offert 1 är sparad.');

        $this->get(route('invoicing.quotes.show', $quote))->assertOk()->assertSee('Offert 1');
        $this->get(route('invoicing.quotes.pdf', $quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::QUOTE, 'document' => $quote])
            ->assertSet('email', 'brollop@exempel.test')
            ->call('send')
            ->assertDispatched('toast', message: 'Offert 1 är skickad till brollop@exempel.test.')
            ->call('toOrder')
            ->assertRedirect();

        $order = SalesDocument::query()->where('kind', SalesDocument::ORDER)->sole();

        Livewire::test(SalesDocumentEditor::class, ['kind' => SalesDocument::ORDER, 'document' => $order])
            ->call('toInvoice')
            ->assertRedirect(route('invoicing.invoices.edit', Invoice::query()->sole()));

        Livewire::test(SalesDocuments::class, ['kind' => SalesDocument::ORDER])->set('filter', 'alla')->assertSee('Fakturerad');
        $this->get(route('invoicing.orders'))->assertOk();
    });

    it('lets an auditor read quotes but not write them', function () {
        $quote = cateringQuote($this->owner, $this->company, $this->customer);
        $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

        $this->get(route('invoicing.quotes.show', $quote))->assertOk()->assertDontSee('Kunden tackade ja');
        $this->get(route('invoicing.quotes.create'))->assertForbidden();
    });
});
