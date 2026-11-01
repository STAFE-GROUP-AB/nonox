<?php

declare(strict_types=1);

namespace App\Support\Developer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/api/openapi.yaml (ADR-032, the single source of truth for the API)
 * read into the structure the developer site renders: tags, operations with
 * their parameters, bodies, responses and examples, scopes, problem codes,
 * webhooks and the OAuth endpoints. Every text keeps its English original
 * and its Swedish x-sv-* variant where the spec has one. The result is cached
 * under the file's SHA-256, so an edited spec is picked up at once.
 *
 * @phpstan-type Text array{en: string, sv: string}
 * @phpstan-type Example array{name: string, summary: ?string, json: string}
 * @phpstan-type Content array{type: string, schema: ?array<string, mixed>, examples: list<Example>}
 * @phpstan-type Parameter array{name: string, in: string, required: bool, description: Text, schema: ?array<string, mixed>, example: mixed}
 * @phpstan-type Response array{status: string, description: string, ref: ?string, headers: list<array{name: string, description: string, type: string}>, contents: list<Content>}
 * @phpstan-type Operation array{id: string, method: string, path: string, tag: string, summary: Text, description: Text, scopes: list<list<string>>, parameters: list<Parameter>, body: ?array{required: bool, description: string, contents: list<Content>}, responses: list<Response>, status: ?string}
 * @phpstan-type Tag array{slug: string, name: string, name_en: string, description: Text, operations: list<string>}
 */
final class ApiSpec
{
    public const string FILE = 'docs/api/openapi.yaml';

    public const array METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function path(): string
    {
        return base_path(self::FILE);
    }

    public static function hash(): string
    {
        return (string) hash_file('sha256', self::path());
    }

    /**
     * The normalized spec.
     *
     * @return array{info: array{title: string, version: string, description: Text}, server: string, tags: array<string, Tag>, operations: array<string, Operation>, scopes: array<string, array{description: Text, operations: list<string>}>, problems: list<array{status: int, code: string, summary: string, detail: string, response: string}>, webhooks: array<string, Operation>, oauth: array<string, mixed>}
     */
    public static function data(): array
    {
        $hash = self::hash();

        /** @phpstan-ignore return.type (the cached value is the array built by build()) */
        return self::$memo[$hash] ??= Cache::rememberForever("developer-portal:spec:{$hash}", fn (): array => self::build(self::parse()));
    }

    /** Forgets the spec parsed in this process (the cache keeps its copy). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /** The spec as JSON, for the download: maps stay objects, so `{}` is not turned into `[]`. */
    public static function json(): string
    {
        $hash = self::hash();

        return (string) Cache::rememberForever("developer-portal:json:{$hash}", fn (): string => (string) json_encode(
            Yaml::parse((string) file_get_contents(self::path()), Yaml::PARSE_OBJECT_FOR_MAP),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n");
    }

    /** @return array<string, mixed> */
    public static function parse(): array
    {
        $spec = Yaml::parse((string) file_get_contents(self::path()));

        return is_array($spec) ? $spec : [];
    }

    public static function tagSlug(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function build(array $spec): array
    {
        $resolver = new RefResolver($spec);
        $tags = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            $slug = self::tagSlug((string) $tag['name']);
            $tags[$slug] = [
                'slug' => $slug,
                'name' => (string) $tag['name'],
                'name_en' => (string) ($tag['x-en-name'] ?? $tag['name']),
                'description' => self::text($tag, 'description'),
                'operations' => [],
            ];
        }

        $operations = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $shared = $item['parameters'] ?? [];

            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }

                $operation = self::operation($resolver, (string) $path, $method, $item[$method], $shared);
                $operations[$operation['id']] = $operation;

                if (isset($tags[$operation['tag']])) {
                    $tags[$operation['tag']]['operations'][] = $operation['id'];
                }
            }
        }

        $webhooks = [];

        foreach ($spec['webhooks'] ?? [] as $event => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $webhooks[(string) $event] = self::operation($resolver, (string) $event, $method, $item[$method], []);
                }
            }
        }

        return [
            'info' => [
                'title' => (string) ($spec['info']['title'] ?? ''),
                'version' => (string) ($spec['info']['version'] ?? ''),
                'description' => self::text($spec['info'] ?? [], 'description'),
            ],
            'server' => (string) ($spec['servers'][0]['url'] ?? ''),
            'tags' => $tags,
            'operations' => $operations,
            'scopes' => self::scopes($spec, $operations),
            'problems' => self::problems($resolver, $spec),
            'webhooks' => $webhooks,
            'oauth' => is_array($spec['x-oauth'] ?? null) ? $spec['x-oauth'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $shared
     * @return Operation
     */
    private static function operation(RefResolver $resolver, string $path, string $method, array $raw, array $shared): array
    {
        $parameters = [];

        // Operation parameters override path-level ones with the same name and location.
        foreach ([...$shared, ...($raw['parameters'] ?? [])] as $parameter) {
            $parameter = $resolver->deref($parameter);
            $parameters[$parameter['in'].':'.$parameter['name']] = [
                'name' => (string) $parameter['name'],
                'in' => (string) $parameter['in'],
                'required' => (bool) ($parameter['required'] ?? false),
                'description' => self::text($parameter, 'description'),
                'schema' => isset($parameter['schema']) ? SchemaTree::build($parameter['schema'], $resolver) : null,
                'example' => $parameter['example'] ?? $resolver->deref($parameter['schema'] ?? [])['example'] ?? null,
            ];
        }

        $body = null;

        if (isset($raw['requestBody'])) {
            $requestBody = $resolver->deref($raw['requestBody']);
            $body = [
                'required' => (bool) ($requestBody['required'] ?? false),
                'description' => (string) ($requestBody['description'] ?? ''),
                'contents' => self::contents($resolver, $requestBody['content'] ?? []),
            ];
        }

        $responses = [];

        foreach ($raw['responses'] ?? [] as $status => $response) {
            $ref = isset($response['$ref']) ? RefResolver::name((string) $response['$ref']) : null;
            $response = $resolver->deref($response);
            $headers = [];

            foreach ($response['headers'] ?? [] as $name => $header) {
                $header = $resolver->deref($header);
                $headers[] = [
                    'name' => (string) $name,
                    'description' => (string) ($header['description'] ?? ''),
                    'type' => SchemaTree::build($header['schema'] ?? [], $resolver)['label'],
                ];
            }

            $responses[] = [
                'status' => (string) $status,
                'description' => (string) ($response['description'] ?? ''),
                'ref' => $ref,
                'headers' => $headers,
                'contents' => self::contents($resolver, $response['content'] ?? []),
            ];
        }

        $scopes = array_values(array_map(
            fn (array $group): array => array_values(array_map('strval', $group)),
            $raw['x-required-scopes']['anyOf'] ?? [],
        ));

        return [
            'id' => (string) $raw['operationId'],
            'method' => $method,
            'path' => $path,
            'tag' => self::tagSlug((string) ($raw['tags'][0] ?? '')),
            'summary' => self::text($raw, 'summary'),
            'description' => self::text($raw, 'description'),
            'scopes' => $scopes,
            'parameters' => array_values($parameters),
            'body' => $body,
            'responses' => $responses,
            'status' => isset($raw['x-nonox-status']) ? (string) $raw['x-nonox-status'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<Content>
     */
    private static function contents(RefResolver $resolver, array $content): array
    {
        $result = [];

        foreach ($content as $type => $media) {
            $examples = [];

            foreach ($media['examples'] ?? [] as $name => $example) {
                $example = $resolver->deref($example);
                $examples[] = [
                    'name' => (string) $name,
                    'summary' => isset($example['summary']) ? (string) $example['summary'] : null,
                    'json' => self::prettyJson($example['value'] ?? null),
                ];
            }

            if ($examples === [] && array_key_exists('example', $media)) {
                $examples[] = ['name' => 'example', 'summary' => null, 'json' => self::prettyJson($media['example'])];
            }

            $result[] = [
                'type' => (string) $type,
                'schema' => isset($media['schema']) ? SchemaTree::build($media['schema'], $resolver) : null,
                'examples' => $examples,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, Operation>  $operations
     * @return array<string, array{description: Text, operations: list<string>}>
     */
    private static function scopes(array $spec, array $operations): array
    {
        $scheme = $spec['components']['securitySchemes']['oauth2'] ?? [];
        $swedish = $scheme['x-sv-scopes'] ?? [];
        $scopes = [];

        foreach ($scheme['flows']['authorizationCode']['scopes'] ?? [] as $scope => $description) {
            $scopes[(string) $scope] = [
                'description' => ['en' => (string) $description, 'sv' => (string) ($swedish[$scope] ?? $description)],
                'operations' => [],
            ];
        }

        foreach ($operations as $id => $operation) {
            foreach (array_unique(array_merge(...$operation['scopes'] ?: [[]])) as $scope) {
                if (isset($scopes[$scope])) {
                    $scopes[$scope]['operations'][] = $id;
                }
            }
        }

        return $scopes;
    }

    /**
     * Every problem code the API documents, from the named examples of the
     * shared problem responses.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array{status: int, code: string, summary: string, detail: string, response: string}>
     */
    private static function problems(RefResolver $resolver, array $spec): array
    {
        $problems = [];

        foreach ($spec['components']['responses'] ?? [] as $name => $response) {
            $response = $resolver->deref($response);

            foreach ($response['content']['application/problem+json']['examples'] ?? [] as $code => $example) {
                $example = $resolver->deref($example);
                $problems[] = [
                    'status' => (int) ($example['value']['status'] ?? 0),
                    'code' => (string) ($example['value']['code'] ?? $code),
                    'summary' => (string) ($example['summary'] ?? ''),
                    'detail' => (string) ($example['value']['detail'] ?? ''),
                    'response' => (string) $name,
                ];
            }
        }

        usort($problems, fn (array $a, array $b): int => [$a['status'], $a['code']] <=> [$b['status'], $b['code']]);

        return $problems;
    }

    /**
     * The English text and its Swedish x-sv-* variant (the English one when there is none).
     *
     * @param  array<string, mixed>  $node
     * @return Text
     */
    public static function text(array $node, string $key): array
    {
        $en = (string) ($node[$key] ?? '');

        return ['en' => $en, 'sv' => (string) ($node["x-sv-{$key}"] ?? $en)];
    }

    /** JSON with two-space indentation, as the examples are shown. */
    public static function prettyJson(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (string) preg_replace_callback('/^( {4})+/m', fn (array $match): string => str_repeat('  ', intdiv(strlen($match[0]), 4)), $json);
    }
}
