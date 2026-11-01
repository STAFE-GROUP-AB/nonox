<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\OAuth\Grants;
use App\Modules\Connect\OAuth\GrantTokens;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectCredential;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\Connect\Support\Problem;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates every /api/v1 request (ADR-032). Only the Authorization
 * header counts: no session, no cookie. The credential is an OAuth access
 * token (a JWT whose grant must still be active) or an API key (nnx_…,
 * looked up by its SHA-256 hash, not expired, not revoked). Either way it
 * is bound to one company:
 *
 * - Connect off for that company: 404, as if the API did not exist.
 * - The person behind it no longer a member: 401, and an OAuth grant is
 *   revoked for good.
 * - Outside the key's or the company's IP allowlist: 403.
 *
 * Implements AuthenticatesRequests so Laravel sorts it before route model
 * binding: models are then looked up inside the company, and another
 * company's ids answer 404.
 */
final class AuthenticateConnect implements AuthenticatesRequests
{
    public function __construct(private readonly ResourceServer $resourceServer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return Problem::unauthenticated();
        }

        $credential = str_starts_with($token, ConnectApiKey::PREFIX)
            ? $this->viaApiKey($token, $request)
            : $this->viaOAuth($token);

        if ($credential instanceof Response) {
            return $credential;
        }

        if (! app(ModuleManager::class)->isEnabled(ConnectModule::KEY, $credential->company)) {
            return Problem::notFound();
        }

        if (! $credential->user->belongsToTeam($credential->company)) {
            if ($credential->kind === ConnectCredential::OAUTH) {
                DB::transaction(fn () => GrantTokens::revoke($credential->id, GrantTokens::MEMBER_LEFT));
            }

            return Problem::unauthenticated();
        }

        if (! ConnectSettings::ipAllowed($request->ip(), ConnectSettings::for($credential->company)['ip_allowlist'])) {
            return Problem::make(403, 'ip_not_allowed', __('Företaget tillåter inte anrop från den här adressen.'));
        }

        if ($credential->company->isArchived() && ! $request->isMethodSafe()) {
            return Problem::make(403, 'company_archived', __('Företaget är arkiverat och kan bara läsas.'));
        }

        ConnectAccess::set($credential);
        CurrentCompany::set($credential->company);
        Auth::guard('web')->setUser($credential->user);
        $request->attributes->set('connect.credential', $credential);

        try {
            return $next($request);
        } finally {
            ConnectAccess::set(null);
            CurrentCompany::forget();
            Auth::guard('web')->forgetUser();
        }
    }

    private function viaApiKey(string $token, Request $request): ConnectCredential|Response
    {
        // Keys are looked up before any company is known; the key names its company.
        $key = CompanyScope::bypass(fn (): ?ConnectApiKey => ConnectApiKey::query()->where('token_hash', ConnectApiKey::hash($token))->first());

        if ($key === null || ! $key->isUsable()) {
            return Problem::unauthenticated();
        }

        if (! ConnectSettings::ipAllowed($request->ip(), $key->ip_allowlist ?? [])) {
            return Problem::make(403, 'ip_not_allowed', __('Nyckeln får inte användas från den här adressen.'));
        }

        $company = Company::query()->find($key->company_id);
        $user = User::query()->find($key->created_by);

        if (! $company instanceof Company || ! $user instanceof User) {
            return Problem::unauthenticated();
        }

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute()) || $key->last_used_ip !== $request->ip()) {
            DB::table('connect_api_keys')->where('id', $key->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }

        return new ConnectCredential(ConnectCredential::API_KEY, (string) $key->id, $company, $user, ConnectScopes::filter($key->scopes), $key->name);
    }

    private function viaOAuth(string $token): ConnectCredential|Response
    {
        $psr = (new ServerRequest('GET', '/'))->withHeader('Authorization', 'Bearer '.$token);

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException) {
            return Problem::unauthenticated();
        }

        $grantId = Grants::forAccessToken((string) $validated->getAttribute('oauth_access_token_id'));

        // Tokens name their grant; the grant names its company.
        $grant = $grantId === null ? null : CompanyScope::bypass(fn (): ?ConnectGrant => ConnectGrant::query()->whereKey($grantId)->first());

        if ($grant === null || ! $grant->isActive() || (string) $grant->user_id !== (string) $validated->getAttribute('oauth_user_id')) {
            return Problem::unauthenticated();
        }

        $client = ConnectApp::query()->find($grant->client_id);
        $company = Company::query()->find($grant->company_id);
        $user = User::query()->find($grant->user_id);

        if (! $client instanceof ConnectApp || $client->revoked || ! $company instanceof Company || ! $user instanceof User) {
            return Problem::unauthenticated();
        }

        if (! ConnectSettings::allowsClient($company, $client->getKey())) {
            return Problem::make(403, 'app_not_allowed', __('Företaget tillåter inte längre den här appen.'));
        }

        /** @var list<string> $tokenScopes */
        $tokenScopes = (array) $validated->getAttribute('oauth_scopes');
        $scopes = array_values(array_intersect(ConnectScopes::filter($grant->scopes), $tokenScopes));

        if ($grant->last_used_at === null || $grant->last_used_at->lt(now()->subMinute())) {
            DB::table('connect_grants')->where('id', $grant->id)->update(['last_used_at' => now()]);
        }

        return new ConnectCredential(ConnectCredential::OAUTH, $grant->id, $company, $user, $scopes, $client->name);
    }
}
