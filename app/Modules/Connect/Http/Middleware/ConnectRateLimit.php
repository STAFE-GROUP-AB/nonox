<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits per credential and per company, per minute (ADR-032). One app
 * cannot use up a company's share, and many apps together cannot overload
 * one company. Over the limit: 429 with Retry-After. Every answer tells the
 * caller its own limit and what is left of it.
 */
final class ConnectRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $credential = ConnectAccess::current();

        if ($credential === null) {
            return $next($request);
        }

        $limits = [
            'connect:credential:'.$credential->key() => (int) config('services.connect.rate_limit_per_credential', 120),
            'connect:company:'.$credential->company->id => (int) config('services.connect.rate_limit_per_company', 600),
        ];

        foreach ($limits as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                return Problem::make(429, 'rate_limited', __('För många anrop. Försök igen om :seconds sekunder.', ['seconds' => $retryAfter]), [], [
                    'Retry-After' => (string) $retryAfter,
                    'RateLimit-Limit' => (string) $limit,
                    'RateLimit-Remaining' => '0',
                    'RateLimit-Reset' => (string) $retryAfter,
                ]);
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 60);
        }

        $own = array_key_first($limits);
        $response = $next($request);
        $response->headers->set('RateLimit-Limit', (string) $limits[$own]);
        $response->headers->set('RateLimit-Remaining', (string) max(0, RateLimiter::remaining($own, $limits[$own])));
        $response->headers->set('RateLimit-Reset', (string) max(0, RateLimiter::availableIn($own)));

        return $response;
    }
}
