<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\OAuth;

use App\Modules\Connect\Support\PendingAuthorization;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The OAuth 2.0 authorization endpoint, /anslut (ADR-032). Checks the app's
 * request with league/oauth2-server (known client, registered redirect URI,
 * known scopes), then insists on what the library leaves optional:
 * response_type=code and a PKCE challenge with S256, for every app. The
 * request is kept in the session and the consent screen shown; nothing is
 * ever approved without it, not even for an app approved before.
 */
final class AuthorizeController
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->query('response_type') !== 'code') {
            return $this->failed(__('Appen bad om ett sätt att logga in som NONOX inte stöder. Bara auktoriseringskod med PKCE stöds.'));
        }

        try {
            $authRequest = $this->server->validateAuthorizationRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException $exception) {
            return $this->failed(__('Begäran från appen kunde inte godtas (:reason). Kontakta appens leverantör.', ['reason' => $exception->getErrorType()]));
        }

        if ($authRequest->getCodeChallenge() === null || $authRequest->getCodeChallengeMethod() !== 'S256') {
            return $this->redirectWithError($authRequest, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $token = PendingAuthorization::put($authRequest);

        return view('connect::consent', ['authToken' => $token]);
    }

    private function failed(string $message): View
    {
        return view('connect::consent-failed', ['message' => $message]);
    }

    private function redirectWithError(AuthorizationRequestInterface $authRequest, string $error, string $description): RedirectResponse
    {
        $exception = new OAuthServerException($description, 3, $error, 400, null, $authRequest->getRedirectUri() ?? $this->firstRedirectUri($authRequest));
        $location = $exception->generateHttpResponse(new PsrResponse, false)->getHeaderLine('Location');
        $separator = str_contains($location, '?') ? '&' : '?';

        return new RedirectResponse($authRequest->getState() === null ? $location : $location.$separator.http_build_query(['state' => $authRequest->getState()]));
    }

    private function firstRedirectUri(AuthorizationRequestInterface $authRequest): ?string
    {
        $uris = $authRequest->getClient()->getRedirectUri();

        return is_array($uris) ? ($uris[0] ?? null) : $uris;
    }
}
