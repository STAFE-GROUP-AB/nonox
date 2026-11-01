<?php

declare(strict_types=1);

use App\Modules\Connect\Support\ConnectScopes;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * docs/api/openapi.yaml is the single source of truth for the public API
 * (ADR-032): every /api/v1 route must be in it, nothing else may be, and
 * every operation carries what the developer site needs.
 */

function openApiSpec(): array
{
    return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
}

/** @return list<string> "METHOD /path" for every /api/v1 route, without the prefix */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route): array => array_map(fn (string $method): string => $method.' '.substr($route->uri(), strlen('api/v1')), array_diff($route->methods(), ['HEAD'])))
        ->sort()->values()->all();
}

it('describes exactly the API routes, no more and no fewer', function () {
    $documented = collect(openApiSpec()['paths'])
        ->flatMap(fn (array $operations, string $path): array => array_map(fn (string $method): string => strtoupper($method).' '.$path, array_keys(array_intersect_key($operations, array_flip(['get', 'post', 'put', 'patch', 'delete'])))))
        ->sort()->values()->all();

    expect($documented)->toBe(apiRoutes());
});

it('gives every operation an id, both languages, a tag, security and error answers', function () {
    $spec = openApiSpec();
    $ids = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_intersect_key($operations, array_flip(['get', 'post', 'put', 'patch', 'delete'])) as $method => $operation) {
            $where = strtoupper($method).' '.$path;
            $ids[] = $operation['operationId'] ?? null;

            expect($operation)->toHaveKeys(['operationId', 'summary', 'description', 'x-sv-summary', 'x-sv-description', 'tags', 'security', 'responses'], $where)
                ->and(array_map('strval', array_keys($operation['responses'])))->toContain('401', '429');

            if ($method === 'post') {
                $parameters = array_column($operation['parameters'] ?? [], '$ref');
                expect($parameters)->toContain('#/components/parameters/IdempotencyKey');
            }
        }
    }

    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('lists the same scopes as the code', function () {
    $scopes = array_keys(openApiSpec()['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']);

    expect($scopes)->toEqualCanonicalizing(ConnectScopes::SCOPES);
});
