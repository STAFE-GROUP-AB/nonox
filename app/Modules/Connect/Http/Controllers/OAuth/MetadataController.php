<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\OAuth;

use App\Modules\Connect\Support\ConnectScopes;
use Illuminate\Http\JsonResponse;

/**
 * OAuth 2.0 authorization server metadata (RFC 8414), so a client can find
 * the endpoints, the scopes and what is required (PKCE with S256).
 */
final class MetadataController
{
    public function __invoke(): JsonResponse
    {
        $issuer = rtrim((string) config('app.url'), '/');

        return new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => route('connect.authorize'),
            'token_endpoint' => route('connect.token'),
            'scopes_supported' => ConnectScopes::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'service_documentation' => 'https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/api/README.md',
            'ui_locales_supported' => ['sv'],
        ], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
