<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict JSON for the API (ADR-032): a body is JSON (multipart only where a
 * route accepts a file), malformed JSON is refused instead of read as
 * empty, every answer is JSON, never cached, never framed, never sets a
 * cookie, and carries a request id to quote in support questions.
 */
final class ConnectJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('connect.request_id', $requestId);
        $request->headers->set('Accept', 'application/json');

        $refused = $this->refuseBody($request);
        $response = $refused ?? $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->remove('Set-Cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }

    private function refuseBody(Request $request): ?Response
    {
        if ($request->isMethodSafe() || $request->getContent() === '' && $request->files->count() === 0) {
            return null;
        }

        $type = strtolower((string) $request->headers->get('Content-Type'));

        if (str_starts_with($type, 'multipart/form-data') || $request->files->count() > 0) {
            return $request->route()?->defaults['connect.multipart'] ?? false
                ? null
                : Problem::make(415, 'unsupported_media_type', __('Skicka JSON (application/json). Bara underlag skickas som multipart/form-data.'));
        }

        if (! str_starts_with($type, 'application/json')) {
            return Problem::make(415, 'unsupported_media_type', __('Skicka JSON (application/json).'));
        }

        $decoded = json_decode($request->getContent(), true, 64);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return Problem::make(400, 'invalid_json', __('Kroppen är inte giltig JSON (ett objekt).'));
        }

        return null;
    }
}
