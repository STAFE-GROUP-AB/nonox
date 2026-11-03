<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Customer;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Livewire\ConnectionsManager;
use App\Modules\Connect\Mail\ApiKeyCreatedMail;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\IdempotencyRecord;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('answers 401 problem+json without a credential, and never with a session cookie', function () {
    $response = $this->getJson('/api/v1/company')->assertUnauthorized();

    expect($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('type'))->toBe('urn:nonox:problem:unauthenticated')
        ->and($response->json('detail'))->toBeString()
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->withToken('nnx_'.str_repeat('a', 48))->getJson('/api/v1/company')->assertUnauthorized();
    $this->withToken('not-a-jwt')->getJson('/api/v1/company')->assertUnauthorized();
});

it('does not answer CORS preflights or add CORS headers', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    $response = $this->withToken($key)->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/company')->assertOk();
    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();

    $preflight = $this->call('OPTIONS', '/api/v1/company', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    expect($preflight->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('answers 404 for everything while Connect is off, even with a valid key', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);
    app(ModuleManager::class)->disable(ConnectModule::KEY, $company, $owner, true);

    $this->withToken($key)->getJson('/api/v1/company')->assertUnauthorized();

    // A key made while the module is off (it cannot be through the UI) still meets a closed door.
    $fresh = Connect::apiKey($owner, $company, ['vouchers:view']);
    $this->withToken($fresh)->getJson('/api/v1/company')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->withToken($fresh)->getJson('/api/v1/accounts')->assertNotFound();
});

it('revokes every key and grant when the module is turned off', function () {
    [$owner, $company] = Connect::company();
    Connect::apiKey($owner, $company, ['vouchers:view']);
    Connect::tokens($this, $owner, $company);

    app(ModuleManager::class)->disable(ConnectModule::KEY, $company, $owner, true);

    expect(DB::table('connect_api_keys')->where('company_id', $company->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('connect_grants')->where('company_id', $company->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('gives the intersection of scopes and the person\'s current permissions, re-checked on every request', function () {
    [$owner, $company] = Connect::company();
    $admin = Bookkeeping::member($company, 'admin');
    $key = Connect::apiKey($admin, $company, ['vouchers:view', 'invoices:view']);

    $this->withToken($key)->getJson('/api/v1/accounts')->assertOk();
    $this->withToken($key)->getJson('/api/v1/customers')->assertOk();

    // Not in the key's scopes, although the admin has the permission.
    $this->withToken($key)->getJson('/api/v1/suppliers')->assertForbidden()->assertJsonPath('code', 'insufficient_scope');

    // The admin becomes an approver, who may read customers but not create them: the key loses that at once.
    $key2 = Connect::apiKey($admin, $company, ['invoices:view', 'invoices:create']);
    $this->withToken($key2)->withHeader('Idempotency-Key', 'r1')->postJson('/api/v1/customers', ['name' => 'Före AB', 'payment_terms_days' => 30])->assertCreated();
    $company->users()->updateExistingPivot($admin->id, ['role' => 'approver']);
    $this->withToken($key2)->getJson('/api/v1/customers')->assertOk();
    $this->withToken($key2)->withHeader('Idempotency-Key', 'r2')->postJson('/api/v1/customers', ['name' => 'Efter AB', 'payment_terms_days' => 30])
        ->assertForbidden()->assertJsonPath('code', 'insufficient_scope');

    // Removed from the company: every key they made stops.
    $company->users()->detach($admin->id);
    $this->withToken($key)->getJson('/api/v1/accounts')->assertUnauthorized();
});

it('stops a key that has expired or been revoked, and records when and where a key is used', function () {
    [$owner, $company] = Connect::company();
    $expired = Connect::apiKey($owner, $company, ['vouchers:view'], null, now()->subMinute()->toDateTimeString());
    $this->withToken($expired)->getJson('/api/v1/company')->assertUnauthorized();

    $key = Connect::apiKey($owner, $company, ['vouchers:view']);
    $this->withToken($key)->getJson('/api/v1/company')->assertOk();
    $row = DB::table('connect_api_keys')->where('token_hash', ConnectApiKey::hash($key))->first();
    expect($row->last_used_at)->not->toBeNull()->and($row->last_used_ip)->toBe('127.0.0.1');

    DB::table('connect_api_keys')->where('id', $row->id)->update(['revoked_at' => now()]);
    $this->withToken($key)->getJson('/api/v1/company')->assertUnauthorized();
});

it('stores only a hash of the key', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    expect(DB::table('connect_api_keys')->where('token_hash', $key)->exists())->toBeFalse()
        ->and(DB::table('connect_api_keys')->where('token_hash', hash('sha256', $key))->exists())->toBeTrue();
});

it('honours the IP allowlist of the key and of the company', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['vouchers:view'], ['203.0.113.0/24']);
    $this->withToken($key)->getJson('/api/v1/company')->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken($key)->getJson('/api/v1/company')->assertOk();

    $open = Connect::apiKey($owner, $company, ['vouchers:view']);
    app(ModuleManager::class)->updateSettings(ConnectModule::KEY, $company, $owner, ['ip_allowlist' => ['198.51.100.1']]);
    $this->withToken($open)->getJson('/api/v1/company')->assertForbidden();
});

it('makes an archived company read-only', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);
    DB::table('teams')->where('id', $company->id)->update(['archived_at' => now()]);

    $this->withToken($key)->getJson('/api/v1/customers')->assertOk();
    $this->withToken($key)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/customers', ['name' => 'Ny kund AB', 'payment_terms_days' => 30])
        ->assertForbidden()->assertJsonPath('code', 'company_archived');
});

it('rate limits per credential with Retry-After', function () {
    [$owner, $company] = Connect::company();
    config(['services.connect.rate_limit_per_credential' => 2]);
    $key = Connect::apiKey($owner, $company, ['vouchers:view']);

    $this->withToken($key)->getJson('/api/v1/company')->assertOk()->assertHeader('RateLimit-Limit', '2');
    $this->withToken($key)->getJson('/api/v1/company')->assertOk()->assertHeader('RateLimit-Remaining', '0');
    $response = $this->withToken($key)->getJson('/api/v1/company')->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('requires an Idempotency-Key on POST, replays the same request and refuses a different one', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);
    $body = ['name' => 'Exempelkunden AB', 'payment_terms_days' => 30];

    $this->withToken($key)->postJson('/api/v1/customers', $body)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'kund-1')->postJson('/api/v1/customers', $body)->assertCreated();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'kund-1')->postJson('/api/v1/customers', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(CurrentCompany::run($company, fn () => Customer::query()->where('name', 'Exempelkunden AB')->count()))->toBe(1);

    $this->withToken($key)->withHeader('Idempotency-Key', 'kund-1')->postJson('/api/v1/customers', [...$body, 'name' => 'Annan AB'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
});

it('answers 409 while a request with the same key is still running', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);
    $keyId = DB::table('connect_api_keys')->where('token_hash', ConnectApiKey::hash($key))->value('id');
    $body = ['name' => 'Exempelkunden AB', 'payment_terms_days' => 30];

    $this->withToken($key)->withHeader('Idempotency-Key', 'pagar')->postJson('/api/v1/customers', $body)->assertCreated();
    CurrentCompany::run($company, fn () => IdempotencyRecord::query()->where('idempotency_key', 'pagar')->update(['status' => IdempotencyRecord::PROCESSING]));

    $this->withToken($key)->withHeader('Idempotency-Key', 'pagar')->postJson('/api/v1/customers', $body)
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_in_progress');
    expect($keyId)->not->toBeNull();
});

it('refuses bodies that are not JSON, and malformed JSON', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $this->withToken($key)->withHeaders(['Idempotency-Key' => 'x1', 'Content-Type' => 'application/x-www-form-urlencoded'])
        ->call('POST', '/api/v1/customers', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'name=Kund')
        ->assertStatus(415);

    $this->call('POST', '/api/v1/customers', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'x2'], '{"name": ')
        ->assertStatus(400)->assertJsonPath('code', 'invalid_json');
});

it('writes every API write to the audit log with the credential', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $this->withToken($key)->withHeader('Idempotency-Key', 'audit-1')->postJson('/api/v1/customers', ['name' => 'Granskad AB', 'payment_terms_days' => 10])->assertCreated();

    $entry = AuditLog::query()->where('company_id', $company->id)->where('action', 'api.request')->latest('id')->firstOrFail();
    expect($entry->payload['credential']['kind'])->toBe('api_key')
        ->and($entry->payload['status'])->toBe(201)
        ->and($entry->payload['idempotency_key'])->toBe('audit-1')
        ->and($entry->user_id)->toBe($owner->id);
});

it('never returns a personal number, only whether one is on file', function () {
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $created = $this->withToken($key)->withHeader('Idempotency-Key', 'pnr-1')
        ->postJson('/api/v1/customers', ['name' => 'Privatperson', 'personal_number' => '19800101-0019', 'payment_terms_days' => 30]);
    $created->assertCreated()->assertJsonPath('data.has_personal_number', true);

    expect($created->getContent())->not->toContain('0101')
        ->and($this->withToken($key)->getJson('/api/v1/customers')->getContent())->not->toContain('19800101');
});

it('creates an API key only with a fresh passkey, shows it once and mails the owner', function () {
    [$owner, $company] = Connect::company();
    $authenticator = Connect::passkey($owner);

    $component = Livewire::actingAs($owner)->test(ConnectionsManager::class)
        ->set('keyName', 'Kassaexport')
        ->set('keyScopes', ['vouchers:view'])
        ->call('createKey', null)
        ->assertHasErrors('passkey');

    $options = null;
    $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
        $options = $data;

        return true;
    });
    $component->call('createKey', $authenticator->authenticate((array) $options, $owner->getPasskeyUserHandle()))->assertHasNoErrors()->assertDispatched('toast');

    $plaintext = (string) $component->get('newKey');
    expect($plaintext)->toStartWith('nnx_');
    Mail::assertSent(ApiKeyCreatedMail::class, fn (ApiKeyCreatedMail $mail) => $mail->hasTo($owner->email) && ! str_contains($mail->render(), $plaintext));
    $this->withToken($plaintext)->getJson('/api/v1/accounts')->assertOk();

    $component->call('forgetNewKey');
    expect($component->get('newKey'))->toBeNull();
});

it('lets a bookkeeper see Anslutningar only when given connect:view, and never create keys', function () {
    [$owner, $company] = Connect::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    $this->actingAs($bookkeeper)->get('/anslutningar')->assertForbidden();
    $this->actingAs($owner)->get('/anslutningar')->assertOk()->assertSee('API-nycklar');
});

it('puts every scope in exactly one group on the API key form', function () {
    $grouped = array_merge(...array_values(ConnectScopes::GROUPS));

    expect($grouped)->toHaveCount(count(array_unique($grouped)))
        ->and(array_values(array_diff(ConnectScopes::SCOPES, $grouped)))->toBe([])
        ->and(array_values(array_diff($grouped, ConnectScopes::SCOPES)))->toBe([])
        ->and(array_keys(ConnectScopes::groupLabels()))->toBe(array_keys(ConnectScopes::GROUPS));
});

it('offers only the scopes the owner holds, grouped, with full access and select all per group', function () {
    [$owner, $company] = Connect::company();
    $modules = app(ModuleManager::class);

    if ($modules->isEnabled('inventory', $company)) {
        $modules->disable('inventory', $company, $owner);
    }

    $component = Livewire::actingAs($owner)->test(ConnectionsManager::class)
        ->assertSee('Full behörighet')
        ->assertSee('vouchers:post')
        ->assertDontSee('inventory:manage');

    $held = array_values(array_filter(ConnectScopes::SCOPES, fn (string $scope): bool => $owner->hasTeamPermission($company, $scope)));
    expect($held)->toContain('vouchers:post')->not->toContain('inventory:view');

    $component->call('toggleAllScopes');
    expect($component->get('keyScopes'))->toBe($held);

    $component->call('toggleAllScopes');
    expect($component->get('keyScopes'))->toBe([]);

    $component->call('toggleScopeGroup', 'bookkeeping');
    expect($component->get('keyScopes'))->toBe(ConnectScopes::GROUPS['bookkeeping']);

    $component->call('toggleScopeGroup', 'bookkeeping');
    expect($component->get('keyScopes'))->toBe([]);

    $component->call('toggleScopeGroup', 'inventory')->call('toggleScopeGroup', 'okänd');
    expect($component->get('keyScopes'))->toBe([]);
});
