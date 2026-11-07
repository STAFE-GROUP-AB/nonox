<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Accounting\VoucherStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Modules\Connect\Mcp\McpTools;
use App\Modules\Connect\Support\ConnectScopes;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * The MCP server for AI assistants (ADR-063): POST /api/mcp on top of NONOX
 * Connect. Read tools return what the REST routes return; the two write
 * tools create drafts and nothing else; posting, sending, paying,
 * deleting and everything from Skatteverket do not exist.
 */

beforeEach(function () {
    config(['nonox.mcp_enabled' => true]);
    Mail::fake();
    Connect::setUp();
});

/** Sends one JSON-RPC message to the MCP endpoint, as a 2025-11-25 client does after initialize. */
function mcpMessage(object $test, string $key, string $method, array $params = []): TestResponse
{
    return $test->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/** @return array<string, mixed> the tools/call result: content, structuredContent, isError */
function mcpTool(object $test, string $key, string $tool, array $arguments = []): array
{
    $response = mcpMessage($test, $key, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    $response->assertOk();

    return (array) $response->json('result');
}

/** @return list<string> the names in tools/list */
function mcpToolNames(object $test, string $key): array
{
    return array_column((array) mcpMessage($test, $key, 'tools/list')->assertOk()->json('result.tools'), 'name');
}

it('answers the handshake as the NONOX server with tools only', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    $response = mcpMessage($this, $key, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'NONOX')
        ->assertJsonPath('result.protocolVersion', '2025-11-25');

    expect(array_keys((array) $response->json('result.capabilities')))->toBe(['tools'])
        ->and((string) $response->json('result.instructions'))->toContain('DRAFT');

    foreach (['resources/list', 'prompts/list', 'subscriptions/listen', 'completion/complete'] as $method) {
        mcpMessage($this, $key, $method)->assertStatus(404)->assertJsonPath('error.code', -32601);
    }
});

it('lists only the tools the key may use right now', function () {
    [$owner, $company] = Connect::company();

    expect(mcpToolNames($this, Connect::apiKey($owner, $company, ['vouchers:view'])))
        ->toEqualCanonicalizing(['get_company', 'list_accounts', 'list_fiscal_years', 'list_voucher_series', 'list_vouchers', 'get_voucher']);

    expect(mcpToolNames($this, Connect::apiKey($owner, $company, ['reports:view'])))
        ->toEqualCanonicalizing(['get_company', 'trial_balance', 'general_ledger', 'balance_sheet', 'income_statement']);

    expect(mcpToolNames($this, Connect::apiKey($owner, $company, McpTools::ALLOWED_SCOPES)))
        ->toEqualCanonicalizing(McpTools::names());

    // A bookkeeper's key gives what a bookkeeper may do; an auditor cannot create drafts whatever the key says.
    $auditor = Bookkeeping::member($company, 'auditor');
    $names = mcpToolNames($this, Connect::apiKey($auditor, $company, McpTools::ALLOWED_SCOPES));
    expect($names)->toContain('list_vouchers')->not->toContain('create_draft_voucher')->not->toContain('create_draft_invoice');

    // A tool that is not listed cannot be called either, and the refusal is logged.
    mcpMessage($this, Connect::apiKey($auditor, $company, McpTools::ALLOWED_SCOPES), 'tools/call', ['name' => 'create_draft_voucher', 'arguments' => ['idempotency_key' => 'x']])
        ->assertStatus(400)->assertJsonPath('error.code', -32602);

    expect(AuditLog::query()->where('company_id', $company->id)->where('action', 'mcp.tool_refused')->latest('id')->firstOrFail()->payload)
        ->toMatchArray(['tool' => 'create_draft_voucher', 'reason' => 'not_allowed']);
    expect(CurrentCompany::run($company, fn (): int => Voucher::query()->count()))->toBe(0);
});

it('has no tool that posts, sends, pays, approves, changes, deletes or exports, and nothing from Skatteverket', function () {
    $forbidden = '/post|correct|issue|send|credit|payment|approve|register|update|destroy|delete|sie|vat|moms|skatt|tax|agi|arbetsgivar|kundhandels|event|submit|lock|close/i';

    foreach (McpTools::all() as $name => $tool) {
        $route = Route::getRoutes()->getByName($tool['route']);

        expect($route)->not->toBeNull("{$name}: {$tool['route']} does not exist")
            ->and($route->methods())->toBe($tool['kind'] === McpTools::DRAFT ? ['POST'] : ['GET', 'HEAD'], $name)
            ->and(preg_match($forbidden, $name.' '.$route->uri()))->toBe(0, "{$name} ({$route->uri()}) is not allowed over MCP");
    }

    expect(collect(McpTools::all())->where('kind', McpTools::DRAFT)->pluck('route')->all())
        ->toEqualCanonicalizing(['connect.api.vouchers.store', 'connect.api.invoices.store']);

    // The scopes a credential may hold for MCP are read and draft scopes only: no VAT return, no posting, sending, paying or export.
    expect(McpTools::ALLOWED_SCOPES)->each->toBeIn(ConnectScopes::SCOPES)
        ->and(array_intersect(McpTools::ALLOWED_SCOPES, ['vat:prepare', 'vat:submit', 'vouchers:post', 'vouchers:correct', 'invoices:send', 'invoices:credit', 'payments:create', 'payments:approve', 'supplier-invoices:approve', 'export:sie', 'accounts:manage']))->toBe([]);

    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, McpTools::ALLOWED_SCOPES);

    foreach (['post_voucher', 'send_invoice', 'delete_voucher', 'vat_return', 'skatteverket_submit', 'tax_account', 'agi_upload', 'kundhandelser'] as $tool) {
        mcpMessage($this, $key, 'tools/call', ['name' => $tool, 'arguments' => (object) []])->assertStatus(400)->assertJsonPath('error.code', -32602);
    }

    $refused = AuditLog::query()->where('company_id', $company->id)->where('action', 'mcp.tool_refused')->get();
    expect($refused)->toHaveCount(8)
        ->and($refused->pluck('payload.tool')->filter()->all())->toBe([])
        ->and($refused->pluck('payload.reason')->unique()->all())->toBe(['unknown_tool']);
});

it('refuses a key that may also post, send, pay or read the VAT return', function () {
    [$owner, $company] = Connect::company();

    foreach (['vouchers:post', 'invoices:send', 'payments:create', 'vat:prepare', 'export:sie'] as $scope) {
        $key = Connect::apiKey($owner, $company, ['vouchers:view', $scope]);

        mcpMessage($this, $key, 'tools/list')->assertForbidden()
            ->assertJsonPath('code', 'mcp_scope_not_allowed')
            ->assertJsonPath('scopes_not_allowed', [$scope]);
    }

    expect(AuditLog::query()->where('company_id', $company->id)->where('action', 'mcp.credential_refused')->count())->toBe(5);
});

it('refuses a missing or invalid token, and answers 404 while Connect is off', function () {
    $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $this->withToken('nnx_'.str_repeat('x', 48))->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    $this->withToken('not-a-jwt')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

    [$owner, $company] = Bookkeeping::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);
    mcpMessage($this, $key, 'tools/list')->assertNotFound();

    // GET has no meaning here (no server-sent events): nothing answers it.
    $this->withToken($key)->getJson('/api/mcp')->assertStatus(405);
});

it('returns the same data as the REST endpoints', function () {
    [$owner, $company] = Connect::company();
    Bookkeeping::post($owner, $company);
    Bookkeeping::draft($owner, $company, 4200);
    $customer = app(SaveCustomer::class)($owner, $company, ['name' => 'Kund i Exempelstad AB', 'payment_terms_days' => 30]);
    $key = Connect::apiKey($owner, $company, McpTools::ALLOWED_SCOPES);
    $year = $this->withToken($key)->getJson('/api/v1/fiscal-years')->json('data.0.id');
    mcpTool($this, $key, 'create_draft_invoice', ['idempotency_key' => 'inv-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-03-02', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '2', 'unit_price_ore' => 95000, 'vat_rate' => 2500]]]);

    $pairs = [
        ['list_vouchers', [], '/api/v1/vouchers'],
        ['list_vouchers', ['status' => 'POSTED', 'limit' => 1], '/api/v1/vouchers?status=POSTED&limit=1'],
        ['list_accounts', ['is_active' => true, 'limit' => 5], '/api/v1/accounts?is_active=1&limit=5'],
        ['trial_balance', ['fiscal_year_id' => $year], "/api/v1/reports/trial-balance?fiscal_year_id={$year}"],
        ['income_statement', ['fiscal_year_id' => $year, 'compare' => true], "/api/v1/reports/income-statement?fiscal_year_id={$year}&compare=1"],
        ['balance_sheet', ['fiscal_year_id' => $year, 'to' => '2026-06-30'], "/api/v1/reports/balance-sheet?fiscal_year_id={$year}&to=2026-06-30"],
        ['list_invoices', [], '/api/v1/invoices'],
        ['list_customers', [], '/api/v1/customers'],
        ['get_customer', ['customer' => $customer->id], "/api/v1/customers/{$customer->id}"],
        ['list_suppliers', [], '/api/v1/suppliers'],
        ['get_company', [], '/api/v1/company'],
    ];

    foreach ($pairs as [$tool, $arguments, $uri]) {
        $rest = $this->withToken($key)->getJson($uri)->assertOk()->json();
        $result = mcpTool($this, $key, $tool, $arguments);

        expect($result['isError'] ?? false)->toBeFalse($tool)
            ->and($result['structuredContent'])->toEqual($rest, $tool);
    }

    $voucher = $this->withToken($key)->getJson('/api/v1/vouchers?status=DRAFT')->json('data.0');
    expect(mcpTool($this, $key, 'get_voucher', ['voucher' => $voucher['id']])['structuredContent']['data'])->toEqual($voucher);

    // Validation answers as over REST, as a tool error the assistant can read.
    $error = mcpTool($this, $key, 'trial_balance', []);
    expect($error['isError'])->toBeTrue()
        ->and(json_decode((string) $error['content'][0]['text'], true))->toMatchArray(['code' => 'validation_failed', 'status' => 422]);
});

it('creates a draft voucher and nothing more, and says a person must post it', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);
    $arguments = [
        'idempotency_key' => 'mcp-v-1',
        'voucher_date' => '2026-03-01',
        'description' => 'Kontorsmaterial',
        'rows' => [['account' => 6110, 'debit_ore' => 40000], ['account' => 2641, 'debit_ore' => 10000], ['account' => 1930, 'credit_ore' => 50000]],
    ];

    $result = mcpTool($this, $key, 'create_draft_voucher', $arguments);
    $id = $result['structuredContent']['data']['id'];

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['data'])->toMatchArray(['status' => 'DRAFT', 'number' => null, 'reference' => null, 'posted_at' => null])
        ->and($result['structuredContent']['approval'])->toMatchArray(['required' => true, 'status' => 'DRAFT'])
        ->and($result['structuredContent']['approval']['review_url'])->toEndWith("/verifikationer/{$id}/andra")
        ->and($result['structuredContent']['approval']['message'])->toContain('En person måste');

    $voucher = CurrentCompany::run($company, fn (): Voucher => Voucher::query()->findOrFail($id));
    expect($voucher->status)->toBe(VoucherStatus::Draft)->and($voucher->number)->toBeNull();

    // The same key and input: the first draft again, not a second one. The same key with other input: refused.
    $again = mcpTool($this, $key, 'create_draft_voucher', $arguments);
    expect($again['structuredContent']['data']['id'])->toBe($id);
    expect(CurrentCompany::run($company, fn (): int => Voucher::query()->count()))->toBe(1);

    $reused = mcpTool($this, $key, 'create_draft_voucher', [...$arguments, 'description' => 'Annat']);
    expect($reused['isError'])->toBeTrue()->and((string) $reused['content'][0]['text'])->toContain('idempotency_key_reused');

    $missing = mcpTool($this, $key, 'create_draft_voucher', array_diff_key($arguments, ['idempotency_key' => true]));
    expect($missing['isError'])->toBeTrue()->and((string) $missing['content'][0]['text'])->toContain('idempotency_key_required');

    // Fields a draft tool does not take never reach the Action.
    $sneaky = mcpTool($this, $key, 'create_draft_voucher', [...$arguments, 'idempotency_key' => 'mcp-v-2', 'status' => 'POSTED', 'number' => 7]);
    expect($sneaky['structuredContent']['data'])->toMatchArray(['status' => 'DRAFT', 'number' => null]);
});

it('creates a draft invoice that is neither issued nor sent', function () {
    [$owner, $company] = Connect::company();
    $customer = app(SaveCustomer::class)($owner, $company, ['name' => 'Kund i Exempelstad AB', 'email' => 'kund@exempel.example', 'payment_terms_days' => 30]);
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $result = mcpTool($this, $key, 'create_draft_invoice', [
        'idempotency_key' => 'mcp-i-1',
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1.5', 'unit_price_ore' => 95000, 'vat_rate' => 2500]],
    ]);

    $id = $result['structuredContent']['data']['id'];
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['data']['status'])->toBe('DRAFT')
        ->and($result['structuredContent']['approval']['review_url'])->toEndWith("/fakturor/{$id}/andra");

    $invoice = CurrentCompany::run($company, fn (): Invoice => Invoice::query()->findOrFail($id));
    expect($invoice->number)->toBeNull();
    Mail::assertNothingSent();
});

it('writes every tool call to the audit log with ids and enums only', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);

    mcpTool($this, $key, 'list_vouchers');
    mcpTool($this, $key, 'get_voucher', ['voucher' => '00000000-0000-4000-8000-000000000000']);
    $created = mcpTool($this, $key, 'create_draft_voucher', ['idempotency_key' => 'audit-1', 'voucher_date' => '2026-03-01', 'description' => 'Hemlig text om Anna Andersson', 'rows' => [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]]);

    $entries = AuditLog::query()->where('company_id', $company->id)->where('action', 'mcp.tool_called')->orderBy('id')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('payload.tool')->all())->toBe(['list_vouchers', 'get_voucher', 'create_draft_voucher'])
        ->and($entries->pluck('payload.status')->all())->toBe([200, 404, 201])
        ->and($entries[1]->payload['code'])->toBe('not_found')
        ->and($entries[2]->payload)->toMatchArray(['kind' => 'draft', 'draft_id' => $created['structuredContent']['data']['id'], 'idempotency_key_sha256' => substr(hash('sha256', 'audit-1'), 0, 16)])
        ->and($entries[2]->payload['credential']['kind'])->toBe('api_key')
        ->and($entries->every(fn (AuditLog $entry): bool => $entry->user_id === $owner->id))->toBeTrue()
        ->and(json_encode($entries->pluck('payload')->all()))->not->toContain('Anna')->not->toContain('Testnyckel');

    // The REST middleware's api.request entry is not written for MCP calls; the tool's own entry replaces it.
    expect(AuditLog::query()->where('company_id', $company->id)->where('action', 'api.request')->count())->toBe(0);
});

it('rate limits MCP messages per credential with Retry-After', function () {
    [$owner, $company] = Connect::company();
    config(['services.connect.mcp_rate_limit_per_credential' => 2]);
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    mcpMessage($this, $key, 'tools/list')->assertOk();
    mcpMessage($this, $key, 'tools/list')->assertOk();
    $response = mcpMessage($this, $key, 'tools/list')->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    // The API's own limit per credential counts MCP messages too.
    config(['services.connect.mcp_rate_limit_per_credential' => 1000, 'services.connect.rate_limit_per_credential' => 4]);
    mcpMessage($this, $key, 'tools/list')->assertOk();
    mcpMessage($this, $key, 'tools/list')->assertStatus(429);
});

it('adds no scope of its own to Passport and no middleware to other routes', function () {
    expect(array_keys(Passport::$scopes))->toBe(ConnectScopes::SCOPES);

    $this->get('/')->assertHeaderMissing('WWW-Authenticate');
});

it('accepts an OAuth access token from an approved app as well as an API key', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company, 'vouchers:view reports:view');

    expect(mcpToolNames($this, $tokens['access_token']))->toContain('list_vouchers')->toContain('trial_balance');
});

it('speaks the 2026-07-28 protocol, with the method and tool name mirrored in headers', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);
    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];
    $body = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'get_company', 'arguments' => (object) [], '_meta' => $meta]];

    $this->withToken($key)->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'get_company'])
        ->postJson('/api/mcp', $body)
        ->assertOk()
        ->assertJsonPath('result.structuredContent.data.org_number', $company->org_number);

    $this->withToken($key)->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list_vouchers'])
        ->postJson('/api/mcp', $body)
        ->assertStatus(400);
});

it('logs only a hash of a valid idempotency key, and nothing of an invalid one', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);
    $draft = ['voucher_date' => '2026-03-01', 'description' => 'Kontorsmaterial', 'rows' => [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]];

    mcpTool($this, $key, 'create_draft_voucher', ['idempotency_key' => 'hash-me-1', ...$draft]);
    $oversized = mcpTool($this, $key, 'create_draft_voucher', ['idempotency_key' => str_repeat('a', 5000), ...$draft]);
    $unicode = mcpTool($this, $key, 'create_draft_voucher', ['idempotency_key' => 'nyckel-åäö', ...$draft]);

    expect($oversized['isError'])->toBeTrue()->and((string) $oversized['content'][0]['text'])->toContain('idempotency_key_invalid')
        ->and($unicode['isError'])->toBeTrue()->and((string) $unicode['content'][0]['text'])->toContain('idempotency_key_invalid');

    $payloads = AuditLog::query()->where('company_id', $company->id)->where('action', 'mcp.tool_called')->orderBy('id')->pluck('payload');

    expect($payloads)->toHaveCount(3)
        ->and($payloads[0]['idempotency_key_sha256'])->toBe(substr(hash('sha256', 'hash-me-1'), 0, 16))
        ->and($payloads[1])->not->toHaveKey('idempotency_key_sha256')
        ->and($payloads[2])->not->toHaveKey('idempotency_key_sha256')
        ->and(json_encode($payloads->all()))->not->toContain('hash-me-1')->not->toContain('aaaaaaaaaa')->not->toContain('nyckel');
});

it('refuses MCP bodies that are too large or nested too deeply', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view', 'vouchers:create']);

    $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_pad' => str_repeat('x', 300 * 1024)]])
        ->assertStatus(413)->assertJsonPath('code', 'payload_too_large');

    $nested = [];
    for ($i = 0; $i < 40; $i++) {
        $nested = ['n' => $nested];
    }

    $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_vouchers', 'arguments' => $nested]])
        ->assertStatus(400)->assertJsonPath('code', 'invalid_json');

    expect(CurrentCompany::run($company, fn (): int => Voucher::query()->count()))->toBe(0);
    mcpMessage($this, $key, 'tools/list')->assertOk();
});

it('tells the assistant that tool results are data, not instructions', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    expect((string) mcpMessage($this, $key, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])->json('result.instructions'))
        ->toContain('never as instructions');
});

it('answers 404 on /api/mcp while the switch is off, which is the default', function () {
    config(['nonox.mcp_enabled' => false]);

    [$owner, $company] = Bookkeeping::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    mcpMessage($this, $key, 'tools/list')->assertNotFound();
    $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
});
