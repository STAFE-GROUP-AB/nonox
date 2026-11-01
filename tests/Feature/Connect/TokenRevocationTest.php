<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Connect\Models\ConnectGrant;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Connect;

/*
 * Token revocation, RFC 7009 (ADR-032): an app ends its own grant; the
 * endpoint never reveals anything about other apps' tokens.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('revokes the whole grant when the app revokes its refresh token', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company);

    $this->post('/oauth/revoke', [
        'token' => $tokens['refresh_token'],
        'token_type_hint' => 'refresh_token',
        'client_id' => $tokens['client_id'],
        'client_secret' => $tokens['client_secret'],
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $grant = CurrentCompany::run($company, fn () => ConnectGrant::query()->sole());
    expect($grant->revoke_reason)->toBe('revoked_by_app')
        ->and(AuditLog::query()->where('action', 'connect.grant.revoked_by_app')->where('company_id', $company->id)->exists())->toBeTrue();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')->assertUnauthorized();
    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        'client_id' => $tokens['client_id'], 'client_secret' => $tokens['client_secret'],
    ])->assertStatus(400);
});

it('revokes with an access token and client_secret_basic, hint or no hint', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company);

    $this->withHeader('Authorization', 'Basic '.base64_encode($tokens['client_id'].':'.$tokens['client_secret']))
        ->post('/oauth/revoke', ['token' => $tokens['access_token']])
        ->assertOk();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')->assertUnauthorized();
});

it('answers 200 and changes nothing for an unknown token or another app\'s token', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company);
    [$other, $otherSecret] = Connect::app('Annan app');

    $this->post('/oauth/revoke', ['token' => 'nonsense', 'client_id' => $tokens['client_id'], 'client_secret' => $tokens['client_secret']])->assertOk();
    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $other->getKey(), 'client_secret' => $otherSecret])->assertOk();
    $this->post('/oauth/revoke', ['token' => $tokens['access_token'], 'client_id' => $other->getKey(), 'client_secret' => $otherSecret])->assertOk();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')->assertOk();
});

it('needs the app to authenticate, and refuses other token types', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company);

    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $tokens['client_id'], 'client_secret' => 'fel'])
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $tokens['client_id']])
        ->assertUnauthorized();
    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'token_type_hint' => 'id_token', 'client_id' => $tokens['client_id'], 'client_secret' => $tokens['client_secret']])
        ->assertStatus(400)->assertJsonPath('error', 'unsupported_token_type');
    $this->post('/oauth/revoke', ['client_id' => $tokens['client_id'], 'client_secret' => $tokens['client_secret']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_request');

    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')->assertOk();
});

it('lets a public app revoke with only its client id, and announces the endpoint', function () {
    [$owner, $company] = Connect::company();
    [$app] = Connect::app('Publik app', public: true);
    $pkce = Connect::pkce();
    $redirect = Connect::consent($this, $owner, Connect::passkey($owner), $app, 'vouchers:view', $pkce['challenge'], $company);
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $app->getKey(), 'redirect_uri' => 'https://app.example/callback',
        'code' => $query['code'], 'code_verifier' => $pkce['verifier'],
    ])->assertOk()->json();

    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $app->getKey(), 'client_secret' => 'gissning'])->assertUnauthorized();
    $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $app->getKey()])->assertOk();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')->assertUnauthorized();

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('revocation_endpoint', route('connect.revoke'));
});
