<?php

declare(strict_types=1);

use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Models\InvoiceDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
