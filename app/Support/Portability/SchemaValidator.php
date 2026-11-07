<?php

declare(strict_types=1);

namespace App\Support\Portability;

use InvalidArgumentException;
use stdClass;

/**
 * Validates a document against the subset of JSON Schema that
 * `docs/portability/nonox-dump.schema.json` uses: type (also as a list),
 * const, enum, required, properties, additionalProperties, items, pattern,
 * minimum, maximum and local `$ref` into `$defs`.
 *
 * It exists so the dump can be checked without adding a package. The whole
 * schema is walked once when the validator is built, so a keyword outside the
 * subset, or one placed next to `$ref`, throws at once instead of being ignored.
 *
 * Instances must be decoded WITHOUT the assoc flag (objects as stdClass), so a
 * JSON object is never mistaken for a list and `{}` differs from `[]`. Use
 * validateJson() or validateFile().
 */
final class SchemaValidator
{
    private const array KEYWORDS = [
        'type', 'const', 'enum', 'required', 'properties', 'additionalProperties',
        'items', 'pattern', 'minimum', 'maximum', 'title', 'description',
    ];

    private const array ROOT_ONLY = ['$schema', '$id', '$defs'];

    private const array TYPES = ['null', 'boolean', 'integer', 'number', 'string', 'array', 'object'];

    /** @param array<string, mixed> $schema Decoded with assoc. */
    public function __construct(private readonly array $schema)
    {
        $this->walk($schema, '#', true);
    }

    public static function forDump(): self
    {
        $path = base_path('docs/portability/nonox-dump.schema.json');
        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new self(is_array($schema) ? $schema : []);
    }

    /** @return list<string> */
    public function validateFile(string $path): array
    {
        return $this->validateJson((string) file_get_contents($path));
    }

    /** @return list<string> One message per violation, with the path of the value. */
    public function validateJson(string $json): array
    {
        return $this->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  mixed  $value  Decoded without assoc.
     * @return list<string>
     */
    public function validate(mixed $value): array
    {
        $problems = [];
        $this->check($value, $this->schema, '$', $problems);

        return $problems;
    }

    /** @param array<mixed> $node */
    private function walk(array $node, string $path, bool $root = false): void
    {
        $allowed = [...self::KEYWORDS, '$ref', ...($root ? self::ROOT_ONLY : [])];

        foreach (array_keys($node) as $keyword) {
            if (! in_array($keyword, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported schema keyword {$keyword} at {$path}.");
            }
        }

        if (isset($node['$ref'])) {
            $siblings = array_diff(array_keys($node), ['$ref', 'description', 'title']);

            if ($siblings !== []) {
                throw new InvalidArgumentException("Keywords next to \$ref are not supported at {$path}.");
            }

            $this->resolve((string) $node['$ref']);
        }

        foreach ((array) ($node['type'] ?? []) as $type) {
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Unsupported type {$type} at {$path}.");
            }
        }

        foreach ((array) ($node['properties'] ?? []) as $name => $child) {
            $this->walk($child, "{$path}/properties/{$name}");
        }

        if (isset($node['items'])) {
            $this->walk($node['items'], "{$path}/items");
        }

        if (is_array($node['additionalProperties'] ?? null)) {
            $this->walk($node['additionalProperties'], "{$path}/additionalProperties");
        }

        foreach ((array) ($node['$defs'] ?? []) as $name => $child) {
            $this->walk($child, "{$path}/\$defs/{$name}");
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     */
    private function check(mixed $value, array $schema, string $path, array &$problems): void
    {
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

        if (is_string($value) && isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', (string) $schema['pattern']).'~D', $value) !== 1) {
            $problems[] = "{$path}: does not match the pattern {$schema['pattern']}.";
        }

        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $problems[] = "{$path}: must be at least {$schema['minimum']}.";
        }

        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            $problems[] = "{$path}: must be at most {$schema['maximum']}.";
        }

        if ($value instanceof stdClass) {
            $this->checkObject($value, $schema, $path, $problems);
        }

        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->check($item, $schema['items'], "{$path}[{$index}]", $problems);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     */
    private function checkObject(stdClass $object, array $schema, string $path, array &$problems): void
    {
        $value = (array) $object;

        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (! array_key_exists($key, $value)) {
                $problems[] = "{$path}: missing required property {$key}.";
            }
        }

        $properties = (array) ($schema['properties'] ?? []);

        foreach ($value as $key => $item) {
            $key = (string) $key;

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
                'array' => is_array($value),
                'object' => $value instanceof stdClass,
                default => false,
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
