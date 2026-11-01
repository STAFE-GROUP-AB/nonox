<?php

declare(strict_types=1);

namespace App\Support\Developer;

/**
 * A JSON Schema from the OpenAPI file as a tree the developer site can
 * render: references resolved (and named, so a reader sees `Voucher`),
 * allOf merged, oneOf/anyOf kept as variants, type lists with "null" turned
 * into a nullable flag, and enums, defaults and limits collected. A schema
 * that refers back to itself stops at the second visit.
 *
 * @phpstan-type Node array{label: string, type: ?string, ref: ?string, nullable: bool, format: ?string, description: array{en: string, sv: string}, enum: list<string>, default: ?string, example: ?string, constraints: list<array{0: string, 1: string}>, readOnly: bool, writeOnly: bool, properties: list<array{name: string, required: bool, node: array<string, mixed>}>, items: ?array<string, mixed>, additional: ?array<string, mixed>, variants: ?array{kind: string, options: list<array<string, mixed>>}, recursive: bool}
 */
final class SchemaTree
{
    private const int MAX_DEPTH = 8;

    private const array CONSTRAINTS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'multipleOf'];

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $refs  references already being expanded, outermost first
     * @return Node
     */
    public static function build(array $schema, RefResolver $resolver, array $refs = []): array
    {
        $ref = null;

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = RefResolver::name($schema['$ref']);

            if (in_array($ref, $refs, true) || count($refs) >= self::MAX_DEPTH) {
                return self::node(['label' => $ref, 'ref' => $ref, 'recursive' => true]);
            }

            $refs[] = $ref;
            $schema = $resolver->deref($schema);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $schema = self::mergeAllOf($schema, $resolver);
        }

        $types = array_values(array_map('strval', (array) ($schema['type'] ?? [])));
        $nullable = in_array('null', $types, true) || ($schema['nullable'] ?? false) === true;
        $type = array_values(array_diff($types, ['null']))[0] ?? (isset($schema['properties']) ? 'object' : null);

        $properties = [];
        $required = array_map('strval', (array) ($schema['required'] ?? []));

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $properties[] = [
                'name' => (string) $name,
                'required' => in_array((string) $name, $required, true),
                'node' => self::build((array) $property, $resolver, $refs),
            ];
        }

        $items = isset($schema['items']) && is_array($schema['items']) ? self::build($schema['items'], $resolver, $refs) : null;
        $additional = isset($schema['additionalProperties']) && is_array($schema['additionalProperties']) ? self::build($schema['additionalProperties'], $resolver, $refs) : null;

        $variants = null;

        foreach (['oneOf', 'anyOf'] as $kind) {
            if (isset($schema[$kind]) && is_array($schema[$kind])) {
                $options = array_values(array_map(fn (mixed $option): array => self::build((array) $option, $resolver, $refs), $schema[$kind]));
                $nonNull = array_values(array_filter($options, fn (array $option): bool => $option['type'] !== 'null'));

                // `oneOf: [X, {type: "null"}]` is a nullable X.
                if (count($nonNull) === 1 && count($options) === 2) {
                    $nullable = true;
                    $type ??= $nonNull[0]['type'];
                    $ref ??= $nonNull[0]['ref'];
                    $properties = $properties ?: $nonNull[0]['properties'];
                    $items ??= $nonNull[0]['items'];

                    continue;
                }

                $variants = ['kind' => $kind, 'options' => $options];
            }
        }

        $constraints = [];

        foreach (self::CONSTRAINTS as $key) {
            if (isset($schema[$key])) {
                $constraints[] = [$key, is_scalar($schema[$key]) ? (string) $schema[$key] : (string) json_encode($schema[$key])];
            }
        }

        return self::node([
            'label' => self::label($ref, $type, $items, $variants),
            'type' => $type,
            'ref' => $ref,
            'nullable' => $nullable,
            'format' => isset($schema['format']) ? (string) $schema['format'] : null,
            'description' => ApiSpec::text($schema, 'description'),
            'enum' => array_values(array_map(fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value), array_filter((array) ($schema['enum'] ?? []), fn (mixed $value): bool => $value !== null))),
            'default' => array_key_exists('default', $schema) ? (string) json_encode($schema['default'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'example' => array_key_exists('example', $schema) ? (string) json_encode($schema['example'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'constraints' => $constraints,
            'readOnly' => ($schema['readOnly'] ?? false) === true,
            'writeOnly' => ($schema['writeOnly'] ?? false) === true,
            'properties' => $properties,
            'items' => $items,
            'additional' => $additional,
            'variants' => $variants,
        ]);
    }

    /**
     * @param  array<mixed>  $schema
     * @return array<mixed>
     */
    private static function mergeAllOf(array $schema, RefResolver $resolver): array
    {
        $merged = $schema;
        unset($merged['allOf']);

        foreach ($schema['allOf'] as $part) {
            $part = $resolver->deref((array) $part);

            if (isset($part['allOf'])) {
                $part = self::mergeAllOf($part, $resolver);
            }

            $merged['properties'] = [...($merged['properties'] ?? []), ...($part['properties'] ?? [])];
            $merged['required'] = array_values(array_unique([...($merged['required'] ?? []), ...($part['required'] ?? [])]));
            $merged['type'] ??= $part['type'] ?? null;
            $merged['description'] ??= $part['description'] ?? null;
        }

        return array_filter($merged, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $items
     * @param  array{kind: string, options: list<array<string, mixed>>}|null  $variants
     */
    private static function label(?string $ref, ?string $type, ?array $items, ?array $variants): string
    {
        if ($ref !== null) {
            return $ref;
        }

        if ($type === 'array') {
            return 'array<'.($items['label'] ?? 'any').'>';
        }

        if ($variants !== null) {
            return implode(' | ', array_map(fn (array $option): string => (string) $option['label'], $variants['options']));
        }

        return $type ?? 'any';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return Node
     */
    private static function node(array $values): array
    {
        /** @var Node */
        return [
            ...[
                'label' => 'any', 'type' => null, 'ref' => null, 'nullable' => false, 'format' => null,
                'description' => ['en' => '', 'sv' => ''], 'enum' => [], 'default' => null, 'example' => null,
                'constraints' => [], 'readOnly' => false, 'writeOnly' => false, 'properties' => [],
                'items' => null, 'additional' => null, 'variants' => null, 'recursive' => false,
            ],
            ...$values,
        ];
    }
}
