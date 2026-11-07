<?php

declare(strict_types=1);

namespace App\Support\Portability;

use InvalidArgumentException;

/**
 * Validates a decoded document against the subset of JSON Schema that
 * `docs/portability/nonox-dump.schema.json` uses: type (also as a list),
 * const, enum, required, properties, additionalProperties: false, items,
 * pattern, minimum, maximum and local `$ref` into `$defs`.
 *
 * It exists so the dump can be checked without adding a package. A keyword
 * outside this subset throws, so the schema cannot silently outgrow it.
 */
final class SchemaValidator
{
    private const array KEYWORDS = [
        '$schema', '$id', '$ref', '$defs', 'title', 'description',
        'type', 'const', 'enum', 'required', 'properties', 'additionalProperties',
        'items', 'pattern', 'minimum', 'maximum',
    ];

    /** @param array<string, mixed> $schema */
    public function __construct(private readonly array $schema) {}

    public static function forDump(): self
    {
        $path = base_path('docs/portability/nonox-dump.schema.json');
        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new self(is_array($schema) ? $schema : []);
    }

    /** @return list<string> One message per violation, with the path of the value. */
    public function validate(mixed $value): array
    {
        $problems = [];
        $this->check($value, $this->schema, '$', $problems);

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     */
    private function check(mixed $value, array $schema, string $path, array &$problems): void
    {
        foreach (array_diff(array_keys($schema), self::KEYWORDS) as $keyword) {
            throw new InvalidArgumentException("Unsupported schema keyword {$keyword} at {$path}.");
        }

        if (isset($schema['$ref'])) {
            $this->check($value, $this->resolve((string) $schema['$ref']), $path, $problems);

            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $problems[] = "{$path}: must equal ".json_encode($schema['const']).'.';

            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = "{$path}: must be one of ".json_encode($schema['enum']).'.';

            return;
        }

        if (isset($schema['type']) && ! $this->typeMatches($value, array_values((array) $schema['type']))) {
            $problems[] = "{$path}: must be of type ".implode('|', (array) $schema['type']).'.';

            return;
        }

        if (is_string($value) && isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', (string) $schema['pattern']).'~', $value) !== 1) {
            $problems[] = "{$path}: does not match the pattern {$schema['pattern']}.";
        }

        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $problems[] = "{$path}: must be at least {$schema['minimum']}.";
        }

        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            $problems[] = "{$path}: must be at most {$schema['maximum']}.";
        }

        if (is_array($value) && ! array_is_list($value) || $value === []) {
            $this->checkObject($value, $schema, $path, $problems);
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->check($item, $schema['items'], "{$path}[{$index}]", $problems);
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     */
    private function checkObject(array $value, array $schema, string $path, array &$problems): void
    {
        if (isset($schema['type']) && ! in_array('object', (array) $schema['type'], true)) {
            return;
        }

        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (! array_key_exists($key, $value)) {
                $problems[] = "{$path}: missing required property {$key}.";
            }
        }

        $properties = (array) ($schema['properties'] ?? []);

        foreach ($value as $key => $item) {
            if (isset($properties[$key])) {
                $this->check($item, $properties[$key], "{$path}.{$key}", $problems);

                continue;
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                $problems[] = "{$path}: unexpected property {$key}.";
            } elseif (is_array($schema['additionalProperties'] ?? null)) {
                $this->check($item, $schema['additionalProperties'], "{$path}.{$key}", $problems);
            }
        }
    }

    /** @param list<string> $types */
    private function typeMatches(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'string' => is_string($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => throw new InvalidArgumentException("Unsupported type {$type}."),
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function resolve(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new InvalidArgumentException("Only local references are supported: {$ref}.");
        }

        $node = $this->schema;

        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node[$part] ?? throw new InvalidArgumentException("Unresolved reference {$ref}.");
        }

        return $node;
    }
}
