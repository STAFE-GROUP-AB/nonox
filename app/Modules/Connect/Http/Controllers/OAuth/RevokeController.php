<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\OAuth;

use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\OAuth\GrantTokens;
use Defuse\Crypto\Crypto;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Throwable;

/**
 * Token revocation, RFC 7009 (ADR-032): an app that is disconnected, or
 * that suspects a token has leaked, tells NONOX to forget it.
 *
 * The app authenticates as at the token endpoint (client_secret_post or
 * client_secret_basic; a public app sends only client_id). The token may be
 * an access token or a refresh token, with or without token_type_hint. A
 * token of the app's own revokes the whole grant, every code and token
 * issued under it (RFC 7009 §2.1 lets revoking one token end the grant):
 * the app must ask the person again. An unknown, expired or already revoked
 * token, or one issued to another app, answers 200 as well and changes
 * nothing (§2.2), so the endpoint reveals nothing about other tokens.
 */
final class RevokeController
{
    private const array HINTS = ['access_token', 'refresh_token'];

    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        [$clientId, $secret] = $this->clientCredentials($request);
        $client = $clientId === null ? null : ConnectApp::query()->find($clientId);

        if (! $client instanceof ConnectApp || $client->revoked || ! $this->authenticates($client, $secret)) {
            return $this->error('invalid_client', 'Client authentication failed.', 401, ['WWW-Authenticate' => 'Basic realm="nonox"']);
        }

        $token = $request->input('token');
        $hint = $request->input('token_type_hint');

        if (! is_string($token) || $token === '') {
            return $this->error('invalid_request', 'The token parameter is required.', 400);
        }

        if ($hint !== null && ! in_array($hint, self::HINTS, true)) {
            return $this->error('unsupported_token_type', 'Only access_token and refresh_token can be revoked.', 400);
        }

        $grantId = $hint === 'access_token'
            ? ($this->grantOfAccessToken($token, $client) ?? $this->grantOfRefreshToken($token, $client))
            : ($this->grantOfRefreshToken($token, $client) ?? $this->grantOfAccessToken($token, $client));

        if ($grantId !== null) {
            DB::transaction(fn () => GrantTokens::revoke($grantId, GrantTokens::REVOKED_BY_APP));
        }

        return new Response('', 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @return array{0: ?string, 1: ?string} client_id and client_secret, from Basic auth or the body */
    private function clientCredentials(Request $request): array
    {
        $basic = $request->headers->get('Authorization');

        if (is_string($basic) && str_starts_with($basic, 'Basic ')) {
            $decoded = base64_decode(substr($basic, 6), true);

            if ($decoded === false || ! str_contains($decoded, ':')) {
                return [null, null];
            }

            [$id, $secret] = explode(':', $decoded, 2);

            return [urldecode($id), urldecode($secret)];
        }

        $id = $request->input('client_id');
        $secret = $request->input('client_secret');

        return [is_string($id) && $id !== '' ? $id : null, is_string($secret) && $secret !== '' ? $secret : null];
    }

    private function authenticates(ConnectApp $client, ?string $secret): bool
    {
        if (! $client->confidential()) {
            return $secret === null;
        }

        return $secret !== null && $this->clients->validateClient((string) $client->getKey(), $secret, null);
    }

    /** The grant of a valid access token issued to this app, or null. */
    private function grantOfAccessToken(string $token, ConnectApp $client): ?string
    {
        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest((new ServerRequest('POST', '/'))->withHeader('Authorization', 'Bearer '.$token));
        } catch (OAuthServerException) {
            return null;
        }

        $grantId = DB::table('oauth_access_tokens')
            ->where('id', (string) $validated->getAttribute('oauth_access_token_id'))
            ->where('client_id', (string) $client->getKey())
            ->value('connect_grant_id');

        return is_string($grantId) ? $grantId : null;
    }

    /** The grant of a refresh token issued to this app, or null. Refresh tokens are encrypted with the application key. */
    private function grantOfRefreshToken(string $token, ConnectApp $client): ?string
    {
        try {
            $payload = json_decode(Crypto::decryptWithPassword($token, Passport::tokenEncryptionKey(app('encrypter'))), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ($payload['client_id'] ?? null) !== (string) $client->getKey() || ! is_string($payload['refresh_token_id'] ?? null)) {
            return null;
        }

        $grantId = DB::table('oauth_refresh_tokens')->where('id', $payload['refresh_token_id'])->value('connect_grant_id');

        return is_string($grantId) ? $grantId : null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(string $code, string $description, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'error_description' => $description], $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
