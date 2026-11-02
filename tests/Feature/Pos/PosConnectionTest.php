<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\VoucherSeries;
use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\DisconnectPos;
use App\Modules\Pos\Actions\SyncProducts;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Http\PosAuthController;
use App\Modules\Pos\Jobs\SyncProductsJob;
use App\Modules\Pos\Livewire\PosPage;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\Pkce;
use App\Modules\Pos\Support\PosApiFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Pos;

/*
 * Connecting tills (ADR-042): OAuth with state bound to user, company and
 * till (and PKCE for Onslip), encrypted credentials, the webhook
 * registered, series K added, token refresh, disconnecting, the page when a
 * till is not configured, and the module's 404 while off.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00:00'));
    [$this->owner, $this->company, $this->places] = Pos::company();
    $this->actingAs($this->owner);
});

function zettleTokenFakes(): void
{
    Http::fake([
        'oauth.zettle.com/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 7200]),
        'oauth.zettle.com/users/self' => Http::response(['uuid' => 'user-1', 'organizationUuid' => 'org-aaaa']),
        'pusher.izettle.com/organizations/self/subscriptions' => Http::sequence()
            ->push([], 200)
            ->push(['uuid' => 'sub', 'signingKey' => 'key-from-zettle', 'status' => 'ACTIVE'], 200),
    ]);
}

it('answers 404 while the module is off', function () {
    [$owner, $company] = Bookkeeping::company();
    Inventory::enable($company, $owner);
    $this->actingAs($owner);

    $this->get(route('pos.index'))->assertNotFound();
    $this->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'x', 'state' => 'y']))->assertNotFound();
});

it('needs Lager and adds series K when turned on, without touching other series', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    VoucherSeries::query()->where('code', 'K')->delete();

    expect(app(ModuleManager::class)->enableCheck(PosModule::KEY, $company)->allowed())->toBeFalse();

    Inventory::enable($company, $owner);
    $before = VoucherSeries::query()->orderBy('code')->pluck('code')->all();
    app(ModuleManager::class)->enable(PosModule::KEY, $company, $owner);

    expect(VoucherSeries::query()->orderBy('code')->pluck('code')->all())->toBe(collect([...$before, 'K'])->sort()->values()->all())
        ->and(VoucherSeries::query()->where('code', 'K')->value('name'))->toBe('Kassa');
});

it('shows a till that is not configured on this server as disabled', function () {
    config(['services.zettle.client_id' => null]);

    $this->get(route('pos.index'))->assertOk()->assertSee('Inte konfigurerad på den här servern');

    Livewire::test(PosPage::class)->call('connect', 'zettle')->assertDispatched('toast', message: 'Inte konfigurerad på den här servern.');
    expect(session(PosAuthController::SESSION))->toBeNull();
});

it('connects Zettle once per state, stores the tokens encrypted and registers the webhook', function () {
    zettleTokenFakes();

    $page = Livewire::test(PosPage::class)->call('connect', 'zettle');
    $flow = session(PosAuthController::SESSION);
    $page->assertRedirectContains('https://oauth.zettle.com/authorize?');

    expect($flow['state'])->toHaveLength(48)->and($flow['verifier'])->toBeNull();

    // A wrong state finishes nothing.
    $this->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => 'fel']))
        ->assertRedirect(route('pos.index'));
    expect(PosConnection::query()->count())->toBe(0);

    // The flow belongs to Zettle, not Onslip.
    $this->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'onslip', 'code' => 'c', 'state' => $flow['state']]))
        ->assertRedirect(route('pos.index'));
    expect(PosConnection::query()->count())->toBe(0);

    $this->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => $flow['state']]))
        ->assertRedirect(route('pos.index'));

    $connection = PosConnection::query()->sole();
    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->external_org_id)->toBe('org-aaaa')
        ->and($connection->credential('access_token'))->toBe('access-1')
        ->and($connection->credential('signing_key'))->toBe('key-from-zettle')
        ->and(VoucherSeries::query()->where('code', 'K')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'pos.connected')->count())->toBe(1)
        ->and($connection->toArray())->not->toHaveKey('credentials');

    $raw = (string) DB::table('pos_connections')->where('id', $connection->id)->value('credentials');
    expect($raw)->not->toContain('access-1')->not->toContain('key-from-zettle');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth.zettle.com/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_secret'] === 'test-client-secret'
        && $request['redirect_uri'] === route('pos.callback', ['provider' => 'zettle']));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'pusher.izettle.com')
        && $request['eventNames'] === ['PurchaseCreated']
        && $request['destination'] === route('pos.webhook.zettle', ['token' => $connection->webhook_token])
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-1/', (string) $request['uuid']) === 1);
    Queue::assertPushed(SyncProductsJob::class);

    // Single use.
    $this->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => $flow['state']]))->assertRedirect(route('pos.index'));
    expect(PosConnection::query()->count())->toBe(1);
});

it('refuses a flow started by another user or company, or one that is too old', function () {
    zettleTokenFakes();
    [$otherOwner, $other] = Pos::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::test(PosPage::class)->call('connect', 'zettle');
    $flow = session(PosAuthController::SESSION);

    $this->actingAs($otherOwner)->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => $flow['state']]))
        ->assertRedirect(route('pos.index'));

    $this->travel(16)->minutes();
    $this->actingAs($this->owner)->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => $flow['state']]));

    expect(PosConnection::query()->withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses a Zettle account already connected to another company', function () {
    zettleTokenFakes();
    [$otherOwner, $other] = Pos::company();
    Pos::connection($other, $otherOwner, Provider::Zettle, org: 'org-aaaa');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::test(PosPage::class)->call('connect', 'zettle');
    $flow = session(PosAuthController::SESSION);

    $this->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'zettle', 'code' => 'c', 'state' => $flow['state']]))
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('toasts', fn (array $toasts): bool => str_contains($toasts[0]['message'], 'redan kopplat till ett annat företag'));

    expect(PosConnection::query()->count())->toBe(0);
});

it('connects Onslip with PKCE and keeps the Hawk credentials encrypted', function () {
    Http::fake([
        'test.onslip360.com/v1/oauth-token.json' => Http::response(['access_token' => 'key:nonox+t1@demo.example', 'secret' => 'hawk-secret', 'realm' => 'demo.example', 'token_type' => 'hawk', 'algorithm' => 'sha256']),
    ]);

    $page = Livewire::test(PosPage::class)->call('connect', 'onslip');
    $flow = session(PosAuthController::SESSION);
    $page->assertRedirectContains('https://test.onslip360.com/oauth-authorization?');
    $page->assertRedirectContains('code_challenge='.Pkce::challenge($flow['verifier']));
    $page->assertRedirectContains('code_challenge_method=S256');

    $this->withSession([PosAuthController::SESSION => $flow])
        ->get(route('pos.callback', ['provider' => 'onslip', 'code' => 'c1', 'state' => $flow['state']]))
        ->assertRedirect(route('pos.index'));

    $connection = PosConnection::query()->sole();
    expect($connection->provider)->toBe(Provider::Onslip)
        ->and($connection->external_org_id)->toBe('demo.example')
        ->and($connection->credential('hawk_key'))->toBe('hawk-secret')
        ->and((string) DB::table('pos_connections')->value('credentials'))->not->toContain('hawk-secret');

    Http::assertSent(fn (Request $request): bool => $request['code_verifier'] === $flow['verifier'] && $request['client_id'] === 'nonox-test' && $request['grant_type'] === 'authorization_code');
});

it('refreshes an expiring Zettle token once and keeps the new refresh token', function () {
    $connection = Pos::connection($this->company, $this->owner);
    $connection->forceFill(['credentials' => [...$connection->credentials, 'expires_at' => time() + 60]])->save();

    Http::fake([
        'oauth.zettle.com/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 7200]),
        'products.izettle.com/*' => Http::response([]),
    ]);

    Inventory::article($this->company, $this->owner);
    app(SyncProducts::class)($connection);

    expect($connection->fresh()->credential('access_token'))->toBe('access-2')
        ->and($connection->fresh()->credential('refresh_token'))->toBe('refresh-2');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer access-2') && str_contains($request->url(), 'products'));
});

it('marks the connection for reconnecting when Zettle refuses the refresh', function () {
    $connection = Pos::connection($this->company, $this->owner);
    $connection->forceFill(['credentials' => [...$connection->credentials, 'expires_at' => time() - 10]])->save();
    Http::fake(['oauth.zettle.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    Inventory::article($this->company, $this->owner);

    expect(fn () => app(SyncProducts::class)($connection))->toThrow(PosApiFailed::class)
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::NeedsReconnect);
});

it('disconnects: asks Zettle to forget the app and the webhook, erases the credentials, keeps the data', function () {
    $connection = Pos::connection($this->company, $this->owner);
    $connection->forceFill(['credentials' => [...$connection->credentials, 'subscription_uuid' => 'sub-1']])->save();
    Http::fake(['*' => Http::response(null, 204)]);

    Livewire::test(PosPage::class)->call('askDisconnect', $connection->id)->call('disconnect')->assertDispatched('toast');

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::Disconnected)
        ->and($connection->credentials)->toBeNull()
        ->and(AuditLog::query()->where('action', 'pos.disconnected')->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://oauth.zettle.com/application-connections/self');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://pusher.izettle.com/organizations/self/subscriptions/sub-1');
});

it('lets only settings:manage connect and disconnect, and inventory:view see the page', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $connection = Pos::connection($this->company, $this->owner);

    $this->actingAs($bookkeeper)->get(route('pos.index'))->assertOk();
    Livewire::actingAs($bookkeeper)->test(PosPage::class)->call('connect', 'zettle')->assertForbidden();
    Livewire::actingAs($bookkeeper)->test(PosPage::class)->call('askDisconnect', $connection->id)->assertForbidden();
    expect(fn () => app(DisconnectPos::class)($bookkeeper, $this->company, $connection))->toThrow(AuthorizationException::class);

    // An auditor sees Lager (inventory:view) and the page, but changes nothing.
    $this->actingAs($auditor)->get(route('pos.index'))->assertOk()->assertDontSee('Koppla bort');

    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver)->get(route('pos.index'))->assertForbidden();
});
