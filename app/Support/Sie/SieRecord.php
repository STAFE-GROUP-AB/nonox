<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * One record (post) of a SIE file: a label such as "#KONTO" and its fields.
 * An object list ({1 "456"}) is a list of strings. A #VER carries its
 * #TRANS, #RTRANS and #BTRANS records as rows.
 */
final class SieRecord
{
    /**
     * @param  list<string|list<string>>  $fields
     * @param  list<SieRecord>  $rows
     */
    public function __construct(
        public readonly string $label,
        public readonly array $fields,
        public readonly int $line,
        public array $rows = [],
    ) {}

    /**
     * A plain field, or null when it is missing or an object list.
     */
    public function field(int $index): ?string
    {
        $field = $this->fields[$index] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * The object list at a position as dimension => object pairs, or null
     * when the field is not an object list.
     *
     * @return array<int|string, string>|null
     */
    public function objects(int $index): ?array
    {
        $field = $this->fields[$index] ?? null;

        if (! is_array($field)) {
            return null;
        }

        $objects = [];

        foreach (array_chunk($field, 2) as $pair) {
            if (count($pair) === 2) {
                $objects[$pair[0]] = $pair[1];
            }
        }

        return $objects;
    }
}
