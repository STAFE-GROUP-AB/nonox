<?php

declare(strict_types=1);

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Actions\Invoicing\SendInvoice;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Jobs\StoreIssuedInvoicePdf;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * The invoice PDF stored as issued (ADR-075, decision O6): rendered once
 * after the issue commits, never changed, and handed out everywhere instead
 * of a new rendering; an invoice from before has none and is rendered.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    config(['nonox.store_issued_invoice_pdfs' => true]);
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['bankgiro' => '5050-1055'])->save();
    CurrentCompany::set($this->company);
    $this->customer = Portal::customer($this->company, $this->owner);
});

it('stores the PDF once when the invoice is issued, and hands out those bytes later', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer);
    $stored = InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole();
    $bytes = (string) Storage::disk('local')->get($stored->path);

    expect(hash('sha256', $bytes))->toBe($stored->sha256)
        ->and($stored->size)->toBe(strlen($bytes));

    $this->company->forceFill(['bankgiro' => '9999-9999'])->save();
    expect(IssuedInvoicePdf::get($invoice->fresh()))->toBe(['bytes' => $bytes, 'stored' => true])
        ->and(IssuedInvoicePdf::store($invoice->fresh())?->id)->toBe($stored->id);

    $this->actingAs($this->owner)->get(route('invoicing.invoices.pdf', ['invoice' => $invoice->id]))->assertOk();
});

it('never changes a stored PDF, and renders an invoice that has none', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer);
    $stored = InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole();

    expect(fn () => DB::transaction(fn () => DB::table('invoice_documents')->where('id', $stored->id)->update(['sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class);

    config(['nonox.store_issued_invoice_pdfs' => false]);
    $older = Portal::invoice($this->company, $this->owner, $this->customer);
    $copy = IssuedInvoicePdf::get($older);

    expect(InvoiceDocument::query()->where('invoice_id', $older->id)->exists())->toBeFalse()
        ->and($copy['stored'])->toBeFalse()
        ->and($copy['bytes'])->toStartWith('%PDF');
});

/** The bytes of a mailable's first attachment. */
function firstAttachmentBytes(Mailable $mail): string
{
    return (string) $mail->attachments()[0]->attachWith(fn () => null, fn (Closure $data) => $data());
}

it('stores the PDF when the e-mail goes before the queued job, and mails exactly those bytes', function () {
    Queue::fake();
    Mail::fake();
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer);
    expect(InvoiceDocument::query()->where('invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($invoice->fresh()->stores_issued_pdf)->toBeTrue();

    app(SendInvoice::class)($this->owner, $invoice->fresh(), 'ekonomi@kontorshuset.test');

    // The attachment is made when the mail is rendered; that stores the PDF, and the mail carries those bytes.
    Mail::assertSent(InvoiceMail::class, fn ($mail): bool => hash('sha256', firstAttachmentBytes($mail)) === InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole()->sha256);

    // The job that runs later only finds it stored.
    (new StoreIssuedInvoicePdf($this->company->id, $invoice->id))->handle();
    expect(InvoiceDocument::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('mails the stored PDF of a recurring invoice issued and sent in one go', function () {
    Queue::fake();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'name' => 'Service', 'interval_months' => 1, 'next_invoice_date' => '2026-06-30', 'send_automatically' => true,
        'rows' => [['description' => 'Service', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
    ]);

    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement);

    Mail::assertSent(InvoiceMail::class, fn ($mail): bool => hash('sha256', firstAttachmentBytes($mail)) === InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole()->sha256);
});

it('deletes a stored PDF only together with its company', function () {
    // A bare company with nothing else that would hold it back from being deleted.
    $other = Company::factory()->create();
    $customer = CurrentCompany::run($other, fn () => Customer::query()->forceCreate(['company_id' => $other->id, 'number' => 1, 'name' => 'Kund']));
    $draft = CurrentCompany::run($other, fn () => Invoice::query()->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-30']));
    CurrentCompany::run($other, fn () => InvoiceDocument::query()->create(['invoice_id' => $draft->id, 'path' => 'invoices/x.pdf', 'sha256' => str_repeat('0', 64), 'size' => 1]));

    expect(fn () => DB::transaction(fn () => DB::table('invoice_documents')->where('company_id', $other->id)->delete()))->toThrow(QueryException::class);

    DB::table('teams')->where('id', $other->id)->delete();
    expect(DB::table('invoice_documents')->where('company_id', $other->id)->exists())->toBeFalse();
});
