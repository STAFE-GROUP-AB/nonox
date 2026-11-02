<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * The public roadmap at /fardplan: what is being built, what is planned and
 * the wishes customers and partners have sent, never bugs or small fixes.
 * The items live in docs/fardplan/fardplan.json. When an item ships it is
 * removed here and shows up under "Klart nyligen", which is read from the
 * changelog (Changelog::latest()).
 *
 * @phpstan-type Item array{id: string, rubrik: string, beskrivning: string, status: string, omrade: string, uppdaterad: string, kommentar?: string}
 */
final class Roadmap
{
    public const string FILE = 'docs/fardplan/fardplan.json';

    /** The columns in the order shown, slug => status. */
    public const array STATUSES = [
        'pagar' => 'Pågår',
        'planerat' => 'Planerat',
        'onskemal' => 'Önskemål',
    ];

    /** @return list<Item> */
    public static function all(): array
    {
        /** @var list<Item> $items */
        $items = self::raw();

        return $items;
    }

    /** @return array<string, list<Item>> keyed by status, newest update first in each, then in the file's order */
    public static function columns(): array
    {
        $columns = array_fill_keys(array_values(self::STATUSES), []);

        foreach (self::all() as $item) {
            $columns[$item['status']][] = $item;
        }

        foreach ($columns as $status => $items) {
            usort($items, fn (array $a, array $b): int => strcmp($b['uppdaterad'], $a['uppdaterad']));
            $columns[$status] = $items;
        }

        return $columns;
    }

    /**
     * Everything wrong with the file: missing fields, unknown statuses or
     * areas, dates that are not ISO dates, duplicate ids.
     *
     * @return list<string>
     */
    public static function errors(): array
    {
        $errors = [];
        $ids = [];

        foreach (self::raw() as $index => $item) {
            $label = is_string($item['id'] ?? null) ? $item['id'] : "#{$index}";

            foreach (['id', 'rubrik', 'beskrivning', 'status', 'omrade', 'uppdaterad'] as $field) {
                $value = $item[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$label}: '{$field}' saknas.";
                }
            }

            if (isset($item['kommentar']) && ! is_string($item['kommentar'])) {
                $errors[] = "{$label}: 'kommentar' ska vara text.";
            }

            if (! in_array($item['status'] ?? null, self::STATUSES, true)) {
                $errors[] = "{$label}: okänd status.";
            }

            if (! in_array($item['omrade'] ?? null, Changelog::AREAS, true)) {
                $errors[] = "{$label}: okänt område.";
            }

            $date = is_string($item['uppdaterad'] ?? null) ? $item['uppdaterad'] : '';

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $errors[] = "{$label}: 'uppdaterad' ska vara ett datum som 2026-11-02.";
            }

            if (isset($ids[$label])) {
                $errors[] = "{$label}: id finns redan.";
            }

            $ids[$label] = true;
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> the items as written, before any check */
    private static function raw(): array
    {
        /** @var array{punkter: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents(base_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

        return $data['punkter'];
    }
}
