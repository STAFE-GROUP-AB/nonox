<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\RegisterPasskey;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Livewire\ConsentScreen;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Helpers for NONOX Connect tests (ADR-032): signing keys, a company with
 * the module on, apps, API keys and the full OAuth flow through the real
 * consent screen with a software passkey.
 */
final class Connect
{
    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    /** Signing keys for Passport, made once per test run, and the passkey origin used by VirtualAuthenticator. */
    public static function setUp(): void
    {
        if (self::$keys === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            $details = openssl_pkey_get_details($resource);
            self::$keys = ['private' => (string) $private, 'public' => (string) ($details['key'] ?? '')];
        }

        config([
            'passport.private_key' => self::$keys['private'],
            'passport.public_key' => self::$keys['public'],
            'passkeys.relying_party_id' => 'nonox.test',
            'passkeys.allowed_origins' => ['https://nonox.test'],
        ]);
    }

    /** @return array{0: User, 1: Company} an owner and a bookkept company with Connect on */
    public static function company(): array
    {
        [$owner, $company] = Bookkeeping::company();
        app(ModuleManager::class)->enable(ConnectModule::KEY, $company, $owner);

        return [$owner->fresh(), $company->fresh()];
    }

    /**
     * An API key made directly, as CreateApiKey would after the passkey step.
     *
     * @param  list<string>  $scopes
     * @param  ?list<string>  $ips
     */
    public static function apiKey(User $user, Company $company, array $scopes, ?array $ips = null, ?string $expires = null): string
    {
        $plaintext = ConnectApiKey::PREFIX.Str::random(48);

        CurrentCompany::run($company, fn () => ConnectApiKey::create([
            'name' => 'Testnyckel',
            'prefix' => substr($plaintext, 0, 12),
            'token_hash' => ConnectApiKey::hash($plaintext),
            'scopes' => $scopes,
            'ip_allowlist' => $ips,
            'expires_at' => $expires ?? now()->addDays(30),
            'created_by' => $user->id,
        ]));

        return $plaintext;
    }

    /** @return array{0: ConnectApp, 1: ?string} the app and its secret (null for a public app) */
    public static function app(string $name = 'Exempelappen', bool $public = false): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://app.example/callback'], ! $public);
        $app = ConnectApp::query()->findOrFail($client->getKey());

        return [$app, $client->plainSecret];
    }

    public static function passkey(User $user): VirtualAuthenticator
    {
        $authenticator = new VirtualAuthenticator;
        app(RegisterPasskey::class)($user, 'Testnyckel', $authenticator->register(PasskeyCeremony::startRegistration($user)));

        return $authenticator;
    }

    /** @return array{verifier: string, challenge: string} */
    public static function pkce(): array
    {
        $verifier = Str::random(64);

        return ['verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public static function authorizeQuery(ConnectApp $app, string $scopes, string $challenge, array $overrides = []): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $app->getKey(),
            'redirect_uri' => 'https://app.example/callback',
            'scope' => $scopes,
            'state' => 'xyz123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            ...$overrides,
        ];
    }

    /**
     * Runs the consent screen to the end and returns the redirect back to the app.
     */
    public static function consent(TestCase $test, User $user, VirtualAuthenticator $authenticator, ConnectApp $app, string $scopes, string $challenge, Company $company): string
    {
        $response = $test->actingAs($user)->get('/anslut?'.http_build_query(self::authorizeQuery($app, $scopes, $challenge)));
        $response->assertOk();
        $token = (string) $response->viewData('authToken');

        $component = Livewire::actingAs($user)->test(ConsentScreen::class, ['authToken' => $token])->set('companyId', $company->id);
        $options = null;
        $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
            $options = $data;

            return true;
        });

        $component->call('approve', $authenticator->authenticate((array) $options, $user->getPasskeyUserHandle()))->assertHasNoErrors();

        return (string) $component->effects['redirect'];
    }

    /**
     * The whole flow: consent, code, tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public static function tokens(TestCase $test, User $user, Company $company, string $scopes = 'vouchers:view', ?VirtualAuthenticator $authenticator = null): array
    {
        [$app, $secret] = self::app();
        $pkce = self::pkce();
        $redirect = self::consent($test, $user, $authenticator ?? self::passkey($user), $app, $scopes, $pkce['challenge'], $company);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $response = $test->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app->getKey(),
            'client_secret' => $secret,
            'redirect_uri' => 'https://app.example/callback',
            'code' => $query['code'] ?? '',
            'code_verifier' => $pkce['verifier'],
        ]);
        $response->assertOk();

        /** @var array{access_token: string, refresh_token: string, expires_in: int, token_type: string} $json */
        $json = $response->json();
        $json['client_id'] = $app->getKey();
        $json['client_secret'] = $secret;

        return $json;
    }
}
