<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * NONOX AI's proposal for an article next to what the form holds: one row
 * per field the AI has a value for that differs from the form, ticked when
 * the form's field is empty. Applying writes only the ticked fields;
 * attributes are added under names the article lacks and never replace one.
 */
final class ArticleSuggestion
{
    /** The fields in the order shown. */
    public const array FIELDS = ['name', 'description', 'brand', 'model', 'colours', 'materials', 'properties', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'gtin'];

    public static function label(string $field): string
    {
        return match ($field) {
            'name' => __('Benämning'),
            'description' => __('Beskrivning'),
            'brand' => __('Varumärke'),
            'model' => __('Modell'),
            'colours' => __('Färger'),
            'materials' => __('Material'),
            'properties' => __('Egenskaper'),
            'weight_grams' => __('Vikt (gram)'),
            'length_mm' => __('Längd (mm)'),
            'width_mm' => __('Bredd (mm)'),
            'height_mm' => __('Höjd (mm)'),
            default => __('EAN/GTIN'),
        };
    }

    /**
     * @param  array<string, mixed>  $suggestion  from SuggestArticleDetails
     * @param  array<string, mixed>  $form  the article form
     * @return list<array{field: string, label: string, current: string, suggested: string, checked: bool}>
     */
    public static function rows(array $suggestion, array $form): array
    {
        $rows = [];

        foreach (self::FIELDS as $field) {
            $suggested = self::value($field, $field === 'properties' ? self::newProperties($suggestion, $form) : ($suggestion[$field] ?? null));
            $current = self::value($field, $form[$field] ?? null);

            if ($suggested === '' || $suggested === $current) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => self::label($field),
                'current' => $current,
                'suggested' => $suggested,
                // Attributes are only ever added, so they are ticked whenever there is something new.
                'checked' => $current === '' || $field === 'properties',
            ];
        }

        return $rows;
    }

    /**
     * The form with the chosen fields filled from the proposal.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $suggestion
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function apply(array $form, array $suggestion, array $fields): array
    {
        foreach (array_intersect(self::FIELDS, $fields) as $field) {
            $form[$field] = match ($field) {
                'colours', 'materials' => implode(', ', (array) ($suggestion[$field] ?? [])),
                'properties' => [...(array) ($form['properties'] ?? []), ...self::newProperties($suggestion, $form)],
                'weight_grams', 'length_mm', 'width_mm', 'height_mm' => ($suggestion[$field] ?? null) === null ? (string) ($form[$field] ?? '') : (string) $suggestion[$field],
                default => (string) ($suggestion[$field] ?? ''),
            };
        }

        return $form;
    }

    /**
     * The proposed attributes whose names the form does not have yet.
     *
     * @param  array<string, mixed>  $suggestion
     * @param  array<string, mixed>  $form
     * @return list<array{key: string, value: string}>
     */
    private static function newProperties(array $suggestion, array $form): array
    {
        $taken = array_map(fn (mixed $property): string => mb_strtolower(trim((string) (((array) $property)['key'] ?? ''))), (array) ($form['properties'] ?? []));
        $new = [];

        foreach ((array) ($suggestion['properties'] ?? []) as $property) {
            $property = (array) $property;
            $key = (string) ($property['key'] ?? '');

            if ($key !== '' && ! in_array(mb_strtolower($key), $taken, true)) {
                $new[] = ['key' => $key, 'value' => (string) ($property['value'] ?? '')];
                $taken[] = mb_strtolower($key);
            }
        }

        return $new;
    }

    /** A field's value as one line of text, empty when there is none. */
    private static function value(string $field, mixed $value): string
    {
        return match (true) {
            $field === 'properties' => implode(', ', array_map(fn (mixed $property): string => trim(((string) (((array) $property)['key'] ?? '')).': '.((string) (((array) $property)['value'] ?? '')), ': '), array_filter((array) $value, fn (mixed $property): bool => trim((string) (((array) $property)['key'] ?? '')) !== ''))),
            is_array($value) => implode(', ', array_map(fn (mixed $item): string => trim((string) $item), $value)),
            $value === null => '',
            default => trim((string) $value),
        };
    }
}
