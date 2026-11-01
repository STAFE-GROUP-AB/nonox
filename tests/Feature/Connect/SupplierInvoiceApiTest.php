<?php

declare(strict_types=1);

use App\Models\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * Supplier invoices and outgoing payments over the API (ADR-032): draft,
 * file, register, approve with segregation of duties, pay.
 */

const PAYABLES_SCOPES = ['supplier-invoices:create', 'supplier-invoices:approve', 'payments:create', 'vouchers:view', 'vouchers:create', 'vouchers:post'];

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
});

/** @return array<string, mixed> */
function supplierInvoiceBody(int $supplierId): array
{
    return [
        'supplier_id' => $supplierId,
        'invoice_number' => 'F-2026-118',
        'ocr' => '4005',
        'invoice_date' => '2026-03-03',
        'total_ore' => 125000,
        'vat_ore' => 25000,
        'rows' => [['account' => 5410, 'amount_ore' => 100000, 'description' => 'Förbrukningsinventarier']],
    ];
}

it('registers, approves with another person and pays a supplier invoice, announcing the approval', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$owner, $company] = Connect::company();
    $approver = Bookkeeping::member($company, 'approver');
    $registrar = Connect::apiKey($owner, $company, PAYABLES_SCOPES);
    $attest = Connect::apiKey($approver, $company, ['supplier-invoices:approve']);
    Connect::webhook($owner, $company, ['supplier_invoice.approved']);

    $supplier = $this->withToken($registrar)->withHeader('Idempotency-Key', 's')->postJson('/api/v1/suppliers', ['name' => 'Leverantören AB', 'payment_terms_days' => 30])->json('data.id');

    $draft = $this->withToken($registrar)->withHeader('Idempotency-Key', 'si-1')->postJson('/api/v1/supplier-invoices', supplierInvoiceBody($supplier))
        ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.total_ore', 125000)->assertJsonPath('data.rows.0.account', 5410);
    $id = $draft->json('data.id');

    $this->withToken($registrar)->patchJson("/api/v1/supplier-invoices/{$id}", ['description' => 'Kontorsmaterial'])->assertOk()
        ->assertJsonPath('data.description', 'Kontorsmaterial')->assertJsonPath('data.total_ore', 125000)->assertJsonPath('data.rows.0.amount_ore', 100000);

    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $this->withToken($registrar)->withHeader('Idempotency-Key', 'si-1-file')
        ->post("/api/v1/supplier-invoices/{$id}/file", ['file' => UploadedFile::fake()->createWithContent('faktura.pdf', $pdf)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.file.sha256', hash('sha256', $pdf))->assertJsonPath('data.file.mime', 'application/pdf');

    $this->withToken($registrar)->withHeader('Idempotency-Key', 'si-1-reg')->postJson("/api/v1/supplier-invoices/{$id}/register")->assertOk()
        ->assertJsonPath('data.status', 'REGISTERED')->assertJsonPath('data.number', 1);

    // The person who registered it may not approve it, whichever key they use.
    $this->withToken($registrar)->withHeader('Idempotency-Key', 'si-1-own')->postJson("/api/v1/supplier-invoices/{$id}/approve")
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    Http::assertNothingSent();

    $this->withToken($attest)->withHeader('Idempotency-Key', 'si-1-ok')->postJson("/api/v1/supplier-invoices/{$id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'APPROVED');
    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['type'] === 'supplier_invoice.approved'
        && json_decode($request->body(), true)['data']['supplier_invoice_id'] === $id);

    $this->withToken($registrar)->withHeader('Idempotency-Key', 'si-1-pay')->postJson("/api/v1/supplier-invoices/{$id}/payments", ['paid_on' => '2026-03-31', 'amount_ore' => 125000])
        ->assertCreated()->assertJsonPath('data.amount_ore', 125000);

    $this->withToken($registrar)->getJson("/api/v1/supplier-invoices/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.remaining_ore', 0)->assertJsonCount(1, 'data.payments');
    $this->withToken($registrar)->getJson('/api/v1/supplier-payments')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($registrar)->getJson('/api/v1/supplier-invoices?status=PAID')->assertOk()->assertJsonCount(1, 'data');
});

it('keeps a registered supplier invoice unchanged, and deletes only drafts', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, PAYABLES_SCOPES);
    $supplier = $this->withToken($key)->withHeader('Idempotency-Key', 's')->postJson('/api/v1/suppliers', ['name' => 'Leverantören AB', 'payment_terms_days' => 30])->json('data.id');

    $draft = $this->withToken($key)->withHeader('Idempotency-Key', 'd-1')->postJson('/api/v1/supplier-invoices', supplierInvoiceBody($supplier))->json('data.id');
    $this->withToken($key)->deleteJson("/api/v1/supplier-invoices/{$draft}")->assertNoContent();

    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'd-2')->postJson('/api/v1/supplier-invoices', supplierInvoiceBody($supplier))->json('data.id');
    $this->withToken($key)->withHeader('Idempotency-Key', 'd-2-reg')->postJson("/api/v1/supplier-invoices/{$id}/register")->assertOk();

    $this->withToken($key)->patchJson("/api/v1/supplier-invoices/{$id}", ['description' => 'Ändrad'])->assertForbidden();
    $this->withToken($key)->deleteJson("/api/v1/supplier-invoices/{$id}")->assertForbidden();
});

it('refuses rows and VAT that do not add up to the total', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, PAYABLES_SCOPES);
    $supplier = $this->withToken($key)->withHeader('Idempotency-Key', 's')->postJson('/api/v1/suppliers', ['name' => 'Leverantören AB', 'payment_terms_days' => 30])->json('data.id');
    $body = supplierInvoiceBody($supplier);
    $body['vat_ore'] = 24000;

    $this->withToken($key)->withHeader('Idempotency-Key', 'x-1')->postJson('/api/v1/supplier-invoices', $body)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['rows']]);
});

it('lets an approver read and approve, but not register', function () {
    [$owner, $company] = Connect::company();
    $approver = Bookkeeping::member($company, 'approver');
    $key = Connect::apiKey($approver, $company, PAYABLES_SCOPES);

    $this->withToken($key)->getJson('/api/v1/supplier-invoices')->assertOk();
    $this->withToken($key)->withHeader('Idempotency-Key', 'ap-1')->postJson('/api/v1/supplier-invoices', ['supplier_id' => 1])
        ->assertForbidden()->assertJsonPath('code', 'insufficient_scope');

    expect(Company::query()->find($company->id)?->separate_supplier_approval)->toBeTrue();
});
