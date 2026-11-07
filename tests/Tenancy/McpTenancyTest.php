<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Modules\Connect\Mcp\McpTools;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * AGENTS.md §13.2 for the MCP server (ADR-063): a key for company A never
 * reads or writes company B through a tool. B's ids answer not found,
 * lists hold only A's records, and a draft cannot point at B's customer.
 */

beforeEach(function () {
    config(['nonox.mcp_enabled' => true]);
    Mail::fake();
    Connect::setUp();
});

/** @return array<string, mixed> the tools/call result for company A's key */
function mcpTenancyCall(object $test, string $key, string $tool, array $arguments = []): array
{
    return (array) $test->withToken($key)
        ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])
        ->assertOk()
        ->json('result');
}

it('never shows company B to a key for company A', function () {
    [$ownerA, $a] = Connect::company();
    [$ownerB, $b] = Connect::company();

    $voucherB = Bookkeeping::post($ownerB, $b);
    $customerB = app(SaveCustomer::class)($ownerB, $b, ['name' => 'B:s kund AB', 'payment_terms_days' => 30]);
    $supplierB = app(SaveSupplier::class)($ownerB, $b, ['name' => 'B:s leverantör AB', 'payment_terms_days' => 30]);
    $invoiceB = app(SaveDraftInvoice::class)($ownerB, $b, ['customer_id' => $customerB->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'B:s rad', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
    $supplierInvoiceB = app(SaveSupplierInvoice::class)($ownerB, $b, ['supplier_id' => $supplierB->id, 'invoice_date' => '2026-03-03', 'total' => '125', 'vat' => '25', 'rows' => [['account' => '5410', 'amount' => '100']]]);
    $yearB = CurrentCompany::run($b, fn () => FiscalYear::query()->firstOrFail());

    Bookkeeping::post($ownerA, $a, 777);
    app(SaveCustomer::class)($ownerA, $a, ['name' => 'A:s kund AB', 'payment_terms_days' => 30]);
    $key = Connect::apiKey($ownerA, $a, McpTools::ALLOWED_SCOPES);

    $forged = [
        ['get_voucher', ['voucher' => $voucherB->id]],
        ['get_customer', ['customer' => $customerB->id]],
        ['get_supplier', ['supplier' => $supplierB->id]],
        ['get_invoice', ['invoice' => $invoiceB->id]],
        ['get_supplier_invoice', ['supplierInvoice' => $supplierInvoiceB->id]],
        ['trial_balance', ['fiscal_year_id' => $yearB->id]],
        ['general_ledger', ['fiscal_year_id' => $yearB->id]],
        ['balance_sheet', ['fiscal_year_id' => $yearB->id]],
        ['income_statement', ['fiscal_year_id' => $yearB->id]],
    ];

    foreach ($forged as [$tool, $arguments]) {
        $result = mcpTenancyCall($this, $key, $tool, $arguments);

        expect($result['isError'])->toBeTrue($tool)
            ->and(json_decode((string) $result['content'][0]['text'], true))->toMatchArray(['status' => 404, 'code' => 'not_found']);
    }

    $lists = [
        'list_vouchers' => [$voucherB->id],
        'list_customers' => [$customerB->id],
        'list_suppliers' => [$supplierB->id],
        'list_invoices' => [$invoiceB->id],
        'list_supplier_invoices' => [$supplierInvoiceB->id],
        'list_fiscal_years' => [$yearB->id],
    ];

    foreach ($lists as $tool => $idsOfB) {
        $ids = array_column((array) mcpTenancyCall($this, $key, $tool)['structuredContent']['data'], 'id');

        expect(array_intersect($ids, $idsOfB))->toBe([], $tool);
    }

    expect(array_column((array) mcpTenancyCall($this, $key, 'list_vouchers')['structuredContent']['data'], 'id'))->not->toBeEmpty();

    // A draft in A cannot name B's customer, and nothing is written in either company.
    $draft = mcpTenancyCall($this, $key, 'create_draft_invoice', ['idempotency_key' => 'forged-1', 'customer_id' => $customerB->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'x', 'quantity' => '1', 'unit_price_ore' => 100, 'vat_rate' => 2500]]]);
    expect($draft['isError'])->toBeTrue()
        ->and(CompanyScope::bypass(fn (): int => Invoice::query()->count()))->toBe(1)
        ->and(CompanyScope::bypass(fn (): int => Voucher::query()->where('company_id', $b->id)->count()))->toBe(1);
});
