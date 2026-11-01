<?php

declare(strict_types=1);

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * Articles, customer invoices and incoming payments over the API
 * (ADR-032), through the same Actions as the UI.
 */

const INVOICE_SCOPES = ['invoices:view', 'invoices:create', 'invoices:send', 'invoices:credit', 'payments:create', 'vouchers:view', 'vouchers:create', 'vouchers:post'];

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
});

/** @return array{0: string, 1: int} an API key and a customer id */
function invoicingKey(object $test, array $scopes = INVOICE_SCOPES): array
{
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, $scopes);
    $customer = $test->withToken($key)->withHeader('Idempotency-Key', 'c-'.uniqid())
        ->postJson('/api/v1/customers', ['name' => 'Kund i Exempelstad AB', 'email' => 'kund@exempel.example', 'payment_terms_days' => 30])
        ->assertCreated()->json('data.id');

    return [$key, $customer];
}

it('creates and changes articles in öre and basis points, and lists them', function () {
    [$key] = invoicingKey($this);

    $created = $this->withToken($key)->withHeader('Idempotency-Key', 'a-1')->postJson('/api/v1/articles', [
        'number' => 'KAFFE', 'name' => 'Kaffe', 'unit' => 'st', 'price_ore' => 3450, 'vat_rate' => 1200,
    ])->assertCreated()
        ->assertJsonPath('data.price_ore', 3450)
        ->assertJsonPath('data.vat_rate', 1200)
        ->assertJsonPath('data.account', 3002);
    $id = $created->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/articles/{$id}", ['price_ore' => 3950])->assertOk()->assertJsonPath('data.price_ore', 3950)->assertJsonPath('data.name', 'Kaffe');
    $this->withToken($key)->patchJson("/api/v1/articles/{$id}", ['price_ore' => 39.5])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->withToken($key)->getJson('/api/v1/articles')->assertOk()->assertJsonPath('data.0.number', 'KAFFE');
    $this->withToken($key)->getJson("/api/v1/articles/{$id}")->assertOk();
});

it('runs an invoice from draft to issued, sent, part paid and paid, with webhooks after each commit', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$key, $customer] = invoicingKey($this);
    $credential = ConnectApiKey::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $owner = User::query()->findOrFail($credential->created_by);
    $company = Company::query()->findOrFail($credential->company_id);
    Connect::webhook($owner, $company, ['invoice.sent', 'invoice.paid', 'voucher.posted']);

    $this->withToken($key)->withHeader('Idempotency-Key', 'art')->postJson('/api/v1/articles', ['number' => 'TIM', 'name' => 'Konsulttimme', 'unit' => 'h', 'price_ore' => 100000, 'vat_rate' => 2500])->assertCreated();

    $draft = $this->withToken($key)->withHeader('Idempotency-Key', 'i-1')->postJson('/api/v1/invoices', [
        'customer_id' => $customer,
        'invoice_date' => '2026-03-02',
        'rows' => [
            ['article' => 'TIM', 'quantity' => 2],
            ['description' => 'Resa', 'quantity' => '1.5', 'unit_price_ore' => 20000, 'vat_rate' => 2500, 'discount_bp' => 1000],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.rows.0.description', 'Konsulttimme')
        ->assertJsonPath('data.rows.0.net_ore', 200000)
        ->assertJsonPath('data.rows.1.net_ore', 27000)
        ->assertJsonPath('data.net_ore', 227000)
        ->assertJsonPath('data.vat_ore', 56750)
        ->assertJsonPath('data.rounding_ore', 50)
        ->assertJsonPath('data.total_ore', 283800)
        ->assertJsonPath('data.due_date', '2026-04-01');
    $id = $draft->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/invoices/{$id}", ['your_reference' => 'Anna Exempel'])->assertOk()
        ->assertJsonPath('data.your_reference', 'Anna Exempel')->assertJsonPath('data.total_ore', 283800);

    $issued = $this->withToken($key)->withHeader('Idempotency-Key', 'i-1-issue')->postJson("/api/v1/invoices/{$id}/issue")->assertOk()
        ->assertJsonPath('data.status', 'ISSUED')->assertJsonPath('data.number', 1);
    expect($issued->json('data.ocr'))->toBeString()->and($issued->json('data.voucher_id'))->toBeString();

    $this->withToken($key)->patchJson("/api/v1/invoices/{$id}", ['notes' => 'Ändrad'])->assertForbidden();

    $this->withToken($key)->withHeader('Idempotency-Key', 'i-1-send')->postJson("/api/v1/invoices/{$id}/send")->assertOk()
        ->assertJsonPath('data.sent_to', 'kund@exempel.example');
    Mail::assertSent(InvoiceMail::class);

    $this->withToken($key)->withHeader('Idempotency-Key', 'i-1-pay-1')->postJson("/api/v1/invoices/{$id}/payments", ['paid_on' => '2026-03-20', 'amount_ore' => 83800])
        ->assertCreated()->assertJsonPath('data.amount_ore', 83800)->assertJsonPath('data.account', 1930);
    $this->withToken($key)->withHeader('Idempotency-Key', 'i-1-pay-2')->postJson("/api/v1/invoices/{$id}/payments", ['paid_on' => '2026-03-28', 'amount_ore' => 200000])->assertCreated();
    $this->withToken($key)->withHeader('Idempotency-Key', 'i-1-pay-3')->postJson("/api/v1/invoices/{$id}/payments", ['paid_on' => '2026-03-28', 'amount_ore' => 1])->assertForbidden();

    $this->withToken($key)->getJson("/api/v1/invoices/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.remaining_ore', 0)->assertJsonCount(2, 'data.payments');
    $this->withToken($key)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');

    $types = collect(Http::recorded())->map(fn (array $pair): string => json_decode($pair[0]->body(), true)['type'])->all();
    expect($types)->toContain('invoice.sent', 'invoice.paid', 'voucher.posted')
        ->and(array_count_values($types)['invoice.paid'])->toBe(1);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['type'] === 'invoice.paid'
        && json_decode($request->body(), true)['data']['invoice_id'] === $id
        && json_decode($request->body(), true)['data']['paid_ore'] === 283800);
});

it('credits an unpaid invoice with a credit draft that is then issued', function () {
    [$key, $customer] = invoicingKey($this);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/invoices', [
        'customer_id' => $customer, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Tjänst', 'quantity' => 1, 'unit_price_ore' => 50000, 'vat_rate' => 2500]],
    ])->json('data.id');
    $this->withToken($key)->withHeader('Idempotency-Key', 'k-1-issue')->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

    $credit = $this->withToken($key)->withHeader('Idempotency-Key', 'k-1-credit')->postJson("/api/v1/invoices/{$id}/credit")->assertCreated()
        ->assertJsonPath('data.kind', 'CREDIT')->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.credits_invoice_id', $id)
        ->assertJsonPath('data.total_ore', -62500);

    $this->withToken($key)->withHeader('Idempotency-Key', 'k-1-credit-issue')->postJson("/api/v1/invoices/{$credit->json('data.id')}/issue")->assertOk()->assertJsonPath('data.status', 'PAID');
    $this->withToken($key)->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.status', 'CREDITED');
});

it('downloads the invoice as a PDF', function () {
    [$key, $customer] = invoicingKey($this);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'p-1')->postJson('/api/v1/invoices', [
        'customer_id' => $customer, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Tjänst', 'quantity' => 1, 'unit_price_ore' => 50000, 'vat_rate' => 2500]],
    ])->json('data.id');

    $response = $this->withToken($key)->get("/api/v1/invoices/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('needs invoices:send to issue and payments:create to register a payment, whatever the person may do', function () {
    [$key, $customer] = invoicingKey($this, ['invoices:view', 'invoices:create']);
    $id = $this->withToken($key)->withHeader('Idempotency-Key', 's-1')->postJson('/api/v1/invoices', [
        'customer_id' => $customer, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Tjänst', 'quantity' => 1, 'unit_price_ore' => 50000, 'vat_rate' => 2500]],
    ])->assertCreated()->json('data.id');

    $this->withToken($key)->withHeader('Idempotency-Key', 's-1-issue')->postJson("/api/v1/invoices/{$id}/issue")
        ->assertForbidden()->assertJsonPath('required_scopes', ['invoices:send', 'vouchers:create', 'vouchers:post'])
        ->assertJsonPath('required_scope_sets', [['invoices:send', 'vouchers:create', 'vouchers:post']]);
    $this->withToken($key)->withHeader('Idempotency-Key', 's-1-pay')->postJson("/api/v1/invoices/{$id}/payments", ['paid_on' => '2026-03-02', 'amount_ore' => 1])
        ->assertForbidden()->assertJsonPath('required_scopes', ['payments:create', 'vouchers:create', 'vouchers:post']);
    expect(CurrentCompany::run(Invoice::query()->withoutGlobalScopes()->findOrFail($id)->company_id, fn () => Invoice::query()->findOrFail($id)->isDraft()))->toBeTrue();
});

it('never returns a customer\'s personal number on an invoice', function () {
    [$key] = invoicingKey($this);
    $customer = $this->withToken($key)->withHeader('Idempotency-Key', 'pn')->postJson('/api/v1/customers', [
        'name' => 'Privatperson Exempel', 'personal_number' => '19811218-9876', 'payment_terms_days' => 10,
    ])->assertCreated()->json('data.id');

    $response = $this->withToken($key)->withHeader('Idempotency-Key', 'pn-i')->postJson('/api/v1/invoices', [
        'customer_id' => $customer, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Städning', 'quantity' => 1, 'unit_price_ore' => 50000, 'vat_rate' => 2500]],
    ])->assertCreated();

    expect((string) $response->getContent())->not->toContain('9876')->not->toContain('personal_number');
});

it('keeps the person\'s own rights: an auditor\'s key reads invoices but cannot write, whatever its scopes', function () {
    [$owner, $company] = Connect::company();
    $auditor = Bookkeeping::member($company, 'auditor');
    $key = Connect::apiKey($auditor, $company, INVOICE_SCOPES);

    $this->withToken($key)->getJson('/api/v1/invoices')->assertOk();
    $this->withToken($key)->withHeader('Idempotency-Key', 'au-1')->postJson('/api/v1/articles', ['number' => 'X', 'name' => 'X', 'unit' => 'st', 'price_ore' => 100, 'vat_rate' => 2500])
        ->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
});
