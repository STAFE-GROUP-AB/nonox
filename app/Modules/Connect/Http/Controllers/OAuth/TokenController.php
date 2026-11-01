<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\OAuth;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth 2.0 token endpoint (ADR-032). Only two grant types exist here:
 * authorization_code (with PKCE, checked by league/oauth2-server against
 * the S256 challenge the consent screen insisted on) and refresh_token.
 * Client credentials, password, implicit, device and personal access
 * grants are refused before Passport sees the request.
 *
 * The whole exchange runs in one transaction: the code or refresh token row
 * is locked, so a parallel second use waits and is then seen as a reuse,
 * which revokes the grant. Errors are answered in OAuth's own format
 * (RFC 6749 §5.2), which every OAuth client library understands.
 */
final class TokenController
{
    private const array GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request): Response
    {
        if (! in_array($request->input('grant_type'), self::GRANT_TYPES, true)) {
            return $this->error('unsupported_grant_type', 'Only authorization_code (with PKCE) and refresh_token are supported.', 400);
        }

        $psrRequest = (new PsrHttpFactory)->createRequest($request);

        $psrResponse = DB::transaction(function () use ($psrRequest): ResponseInterface {
            try {
                return $this->server->respondToAccessTokenRequest($psrRequest, new PsrResponse);
            } catch (OAuthServerException $exception) {
                // Committed, not rolled back: a detected reuse must stay revoked.
                return $exception->generateHttpResponse(new PsrResponse);
            }
        });

        $response = (new HttpFoundationFactory)->createResponse($psrResponse);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function error(string $code, string $description, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }
}
