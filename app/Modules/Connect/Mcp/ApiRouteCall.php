<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use App\Modules\Connect\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Connect\Http\Middleware\RequireScope;
use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs one NONOX API REST route (ADR-032) for an MCP tool (ADR-063),
 * inside the MCP request that AuthenticateConnect has already bound to a
 * credential and a company. Nothing is re-implemented: the route's own
 * `connect.scope` middleware, its Idempotency-Key handling for POST, route
 * model binding (scoped to the credential's company, so another company's
 * id is not found), the controller, its Action and Policy, and the problem
 * answers for errors are exactly those of the REST API. The tool therefore
 * returns the same data as the REST endpoint, and can never do more.
 */
final class ApiRouteCall
{
    /**
     * @param  array<string, string|int>  $parameters  route parameters
     * @param  array<string, mixed>  $query  query string for a GET
     * @param  ?array<string, mixed>  $body  JSON body for a POST
     */
    public static function run(Request $outer, string $routeName, array $parameters = [], array $query = [], ?array $body = null, ?string $idempotencyKey = null): ApiResult
    {
        $template = RouteFacade::getRoutes()->getByName($routeName);

        if (! $template instanceof Route) {
            return ApiResult::from(Problem::notFound());
        }

        $route = clone $template;
        $method = in_array('POST', $route->methods(), true) ? 'POST' : 'GET';
        $sub = self::request($outer, $route, $method, $parameters, $query, $body, $idempotencyKey);

        // A path parameter that does not fit the route (not a uuid, not a number) is simply not found, as over REST.
        if (! $route->matches($sub)) {
            return ApiResult::from(Problem::notFound());
        }

        $route->bind($sub);
        $sub->setRouteResolver(fn (): Route => $route);

        $core = fn (Request $request): Response => self::dispatch($route, $request);
        $withIdempotency = $method === 'POST'
            ? fn (Request $request): Response => app(RequireIdempotencyKey::class)->handle($request, $core)
            : $core;
        $scopes = self::scopes($route);

        $response = self::withRequest($sub, fn (): Response => $scopes === null
            ? $withIdempotency($sub)
            : app(RequireScope::class)->handle($sub, $withIdempotency, $scopes));

        return ApiResult::from($response);
    }

    /** The scope expression of the route's `connect.scope:` middleware ("a|b", "a+b"), or null when it names none. */
    public static function scopes(Route $route): ?string
    {
        foreach ($route->middleware() as $middleware) {
            if (str_starts_with($middleware, 'connect.scope:')) {
                return substr($middleware, strlen('connect.scope:'));
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|int>  $parameters
     * @param  array<string, mixed>  $query
     * @param  ?array<string, mixed>  $body
     */
    private static function request(Request $outer, Route $route, string $method, array $parameters, array $query, ?array $body, ?string $idempotencyKey): Request
    {
        $path = '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', fn (array $match): string => rawurlencode((string) ($parameters[$match[1]] ?? '')), $route->uri()), '/');

        $server = [
            'REMOTE_ADDR' => $outer->server('REMOTE_ADDR', '127.0.0.1'),
            'HTTP_HOST' => $outer->getHttpHost(),
            'HTTPS' => $outer->isSecure() ? 'on' : 'off',
            'HTTP_USER_AGENT' => (string) $outer->userAgent(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $content = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sub = Request::create($path, $method, $method === 'GET' ? $query : [], [], [], $server, $content);

        foreach (['connect.request_id', 'connect.credential'] as $attribute) {
            $sub->attributes->set($attribute, $outer->attributes->get($attribute));
        }

        return $sub;
    }

    /** Route model binding, the controller, and errors as the REST API answers them. */
    private static function dispatch(Route $route, Request $request): Response
    {
        try {
            /** @var Router $router */
            $router = app('router');
            $router->substituteBindings($route);
            $router->substituteImplicitBindings($route);

            return Router::toResponse($request, $route->run());
        } catch (Throwable $exception) {
            $problem = Problem::fromException($exception, $request);

            if ($problem !== null && $problem->getStatusCode() >= 500) {
                report($exception);
            }

            return $problem ?? (method_exists($exception, 'getResponse') && $exception->getResponse() instanceof Response
                ? $exception->getResponse()
                : Problem::make(500, 'server_error', __('Något gick fel hos NONOX. Försök igen om en stund.')));
        }
    }

    /**
     * The controller resolves its Request from the container: swap in the
     * inner request for the call and put the MCP request back afterwards.
     *
     * @param  Closure(): Response  $callback
     */
    private static function withRequest(Request $sub, Closure $callback): Response
    {
        $previous = app('request');
        app()->instance('request', $sub);

        try {
            return $callback();
        } finally {
            app()->instance('request', $previous);
        }
    }
}
