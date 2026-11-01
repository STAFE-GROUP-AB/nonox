<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Connect\Livewire\ConsentScreen;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\OAuth\GrantTokens;
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

it('runs authorization code with PKCE end to end and the token reaches only its own company', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company, 'vouchers:view');

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['expires_in'])->toBeLessThanOrEqual(15 * 60)
        ->and($tokens['refresh_token'])->not->toBe('');

    $this->withToken($tokens['access_token'])->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('data.id', $company->id)
        ->assertJsonPath('data.credential.type', 'oauth')
        ->assertJsonPath('data.credential.scopes', ['vouchers:view']);

    $grant = CurrentCompany::run($company, fn () => ConnectGrant::query()->sole());
    expect($grant->user_id)->toBe($owner->id)
        ->and(AuditLog::query()->where('action', 'connect.grant.created')->where('company_id', $company->id)->exists())->toBeTrue();
});

it('refuses a request without PKCE, with plain PKCE or for another response type', function () {
    [$owner] = Connect::company();
    [$app] = Connect::app();
    $pkce = Connect::pkce();

    $this->actingAs($owner)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', $pkce['challenge'], ['code_challenge_method' => 'plain'])))
        ->assertRedirect()
        ->assertRedirectContains('error=invalid_request');

    $query = Connect::authorizeQuery($app, 'vouchers:view', $pkce['challenge']);
    unset($query['code_challenge'], $query['code_challenge_method']);
    $this->actingAs($owner)->get('/anslut?'.http_build_query($query))->assertRedirectContains('error=invalid_request');

    $this->actingAs($owner)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', $pkce['challenge'], ['response_type' => 'token'])))
        ->assertOk()->assertViewIs('connect::consent-failed');

    $this->actingAs($owner)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', $pkce['challenge'], ['redirect_uri' => 'https://evil.example/cb'])))
        ->assertOk()->assertViewIs('connect::consent-failed');
});

it('accepts only the authorization code and refresh token grants at the token endpoint', function (string $grant) {
    $this->post('/oauth/token', ['grant_type' => $grant, 'client_id' => 'x', 'client_secret' => 'y', 'username' => 'a', 'password' => 'b'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'unsupported_grant_type');
})->with(['client_credentials', 'password', 'implicit', 'personal_access', 'urn:ietf:params:oauth:grant-type:device_code']);

it('needs the right code verifier', function () {
    [$owner, $company] = Connect::company();
    [$app, $secret] = Connect::app();
    $pkce = Connect::pkce();
    $redirect = Connect::consent($this, $owner, Connect::passkey($owner), $app, 'vouchers:view', $pkce['challenge'], $company);
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($query['state'])->toBe('xyz123');

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $app->getKey(), 'client_secret' => $secret,
        'redirect_uri' => 'https://app.example/callback', 'code' => $query['code'], 'code_verifier' => Connect::pkce()['verifier'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('rotates refresh tokens and revokes the whole grant when a used one comes back', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company, 'vouchers:view');
    $refresh = fn (string $token) => $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $token,
        'client_id' => $tokens['client_id'], 'client_secret' => $tokens['client_secret'],
    ]);

    $second = $refresh($tokens['refresh_token'])->assertOk()->json();
    expect($second['refresh_token'])->not->toBe($tokens['refresh_token']);

    $this->withToken($second['access_token'])->getJson('/api/v1/company')->assertOk();

    // The first refresh token again: someone else has it. Everything under the grant stops.
    $refresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $refresh($second['refresh_token'])->assertStatus(400);
    $this->withToken($second['access_token'])->getJson('/api/v1/company')->assertUnauthorized();

    $grant = CurrentCompany::run($company, fn () => ConnectGrant::query()->sole());
    expect($grant->revoke_reason)->toBe(GrantTokens::REFRESH_TOKEN_REUSED)
        ->and(AuditLog::query()->where('action', 'connect.grant.refresh_token_reused')->where('company_id', $company->id)->exists())->toBeTrue();
});

it('revokes the grant when an authorization code is used twice', function () {
    [$owner, $company] = Connect::company();
    [$app, $secret] = Connect::app();
    $pkce = Connect::pkce();
    $redirect = Connect::consent($this, $owner, Connect::passkey($owner), $app, 'vouchers:view', $pkce['challenge'], $company);
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    $exchange = fn () => $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $app->getKey(), 'client_secret' => $secret,
        'redirect_uri' => 'https://app.example/callback', 'code' => $query['code'], 'code_verifier' => $pkce['verifier'],
    ]);

    $first = $exchange()->assertOk()->json();
    $exchange()->assertStatus(400);

    $this->withToken($first['access_token'])->getJson('/api/v1/company')->assertUnauthorized();
    expect(CurrentCompany::run($company, fn () => ConnectGrant::query()->sole())->revoke_reason)->toBe(GrantTokens::CODE_REUSED);
});

it('needs a passkey on the server, not only in the browser', function () {
    [$owner, $company] = Connect::company();
    [$app] = Connect::app();
    Connect::passkey($owner);
    $response = $this->actingAs($owner)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', Connect::pkce()['challenge'])));

    Livewire::actingAs($owner)->test(ConsentScreen::class, ['authToken' => (string) $response->viewData('authToken')])
        ->set('companyId', $company->id)
        ->call('approve', null)
        ->assertHasErrors('passkey');

    expect(CurrentCompany::run($company, fn () => ConnectGrant::query()->count()))->toBe(0);
});

it('lets only owners and administrators grant, and only in companies with Connect on', function () {
    [$owner, $company] = Connect::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');
    [, $other] = Bookkeeping::company();
    [$app] = Connect::app();
    Connect::passkey($bookkeeper);

    $response = $this->actingAs($bookkeeper)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', Connect::pkce()['challenge'])));

    Livewire::actingAs($bookkeeper)->test(ConsentScreen::class, ['authToken' => (string) $response->viewData('authToken')])
        ->assertSee('Du kan inte ansluta appen till något företag')
        ->set('companyId', $company->id)
        ->call('approve', null)
        ->assertHasErrors('companyId');

    expect($other->id)->not->toBe($company->id);
});

it('sends the app back with access_denied when the person declines', function () {
    [$owner] = Connect::company();
    [$app] = Connect::app();
    $response = $this->actingAs($owner)->get('/anslut?'.http_build_query(Connect::authorizeQuery($app, 'vouchers:view', Connect::pkce()['challenge'])));

    $component = Livewire::actingAs($owner)->test(ConsentScreen::class, ['authToken' => (string) $response->viewData('authToken')])->call('deny');

    expect((string) $component->effects['redirect'])->toStartWith('https://app.example/callback')->toContain('error=access_denied');
});

it('publishes authorization server metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
});

it('keeps tokens apart: each token row names its grant', function () {
    [$owner, $company] = Connect::company();
    Connect::tokens($this, $owner, $company);

    expect(DB::table('oauth_access_tokens')->whereNull('connect_grant_id')->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->whereNull('connect_grant_id')->count())->toBe(0);
});
