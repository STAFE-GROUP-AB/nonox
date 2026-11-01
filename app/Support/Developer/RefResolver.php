<?php

declare(strict_types=1);

namespace App\Support\Developer;

use InvalidArgumentException;

/**
 * Resolves local JSON pointers (`#/components/schemas/Voucher`) in the
 * OpenAPI file. Only references inside the file are allowed; the spec has
 * no external ones and the site never fetches anything.
 */
final class RefResolver
{
    /** @param array<string, mixed> $spec */
    public function __construct(private readonly array $spec) {}

    /**
     * The node a `$ref` points to (followed until it is not a reference), or the node itself.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    public function deref(array $node): array
    {
        $seen = [];

        while (isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];

            if (isset($seen[$ref])) {
                throw new InvalidArgumentException("Circular reference {$ref}.");
            }

            $seen[$ref] = true;
            $node = $this->lookup($ref);
        }

        return $node;
    }

    /** @return array<mixed> */
    public function lookup(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new InvalidArgumentException("Only local references are supported, not {$ref}.");
        }

        $node = $this->spec;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new InvalidArgumentException("Unknown reference {$ref}.");
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new InvalidArgumentException("Reference {$ref} is not an object.");
        }

        return $node;
    }

    /** The last segment of a reference: `#/components/schemas/Voucher` is `Voucher`. */
    public static function name(string $ref): string
    {
        $position = strrpos($ref, '/');

        return $position === false ? $ref : substr($ref, $position + 1);
    }
}
