<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Models\RegisterImportPreset;

/**
 * The shipped presets (docs/migration/format-presets.json) and the ones a
 * company saved. A preset only pre-fills the mapping by header name; it
 * never blocks an import (ADR-076, "Presets: data, not code").
 */
final class PresetRepository
{
    public const string FILE = 'docs/migration/format-presets.json';

    /** @var ?list<array<string, mixed>> */
    private static ?array $shipped = null;

    /** @return list<array<string, mixed>> */
    public static function shipped(): array
    {
        if (self::$shipped !== null) {
            return self::$shipped;
        }

        $decoded = json_decode((string) file_get_contents(base_path(self::FILE)), true);

        return self::$shipped = array_values((array) ($decoded['presets'] ?? []));
    }

    /**
     * The shipped presets that exist for a kind, program slug => name.
     *
     * @return array<string, string>
     */
    public static function programs(RegisterKind $kind): array
    {
        $programs = [];

        foreach (self::shipped() as $preset) {
            if ($preset['kind'] === $kind->value && ($preset['headers'] ?? []) !== []) {
                $programs[(string) $preset['program']] = (string) $preset['name'];
            }
        }

        return $programs;
    }

    /** @return ?array<string, mixed> */
    public static function find(RegisterKind $kind, string $program): ?array
    {
        foreach (self::shipped() as $preset) {
            if ($preset['kind'] === $kind->value && $preset['program'] === $program) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * The mapping a source pre-fills for the headers of a file: NONOX field
     * => the file's own header, matched without regard to case, spaces,
     * dots, hyphens and underscores. Headers the file lacks are skipped, so
     * a missing or extra column never matters.
     *
     * @param  list<string>  $fileHeaders
     * @return array{mapping: array<string, string>, value_maps: array<string, array<string, string>>}
     */
    public static function suggest(RegisterKind $kind, string $source, array $fileHeaders): array
    {
        $byNormalised = [];

        foreach ($fileHeaders as $header) {
            $byNormalised[RegisterFileReader::normaliseHeader($header)] ??= $header;
        }

        $mapping = [];
        $valueMaps = [];

        if (str_starts_with($source, 'company:')) {
            $preset = RegisterImportPreset::query()->where('kind', $kind->value)->whereKey((int) substr($source, 8))->first();

            if ($preset !== null) {
                foreach ($preset->mapping as $field => $header) {
                    $hit = $byNormalised[RegisterFileReader::normaliseHeader($header)] ?? null;

                    if ($hit !== null && array_key_exists($field, $kind->fields())) {
                        $mapping[$field] = $hit;
                    }
                }
            }

            return ['mapping' => $mapping, 'value_maps' => []];
        }

        $entries = $source === 'nonox'
            ? array_map(fn (string $field, array $meta): array => ['header' => $meta['label'], 'field' => $field], array_keys($kind->fields()), array_values($kind->fields()))
            : (array) (self::find($kind, $source)['headers'] ?? []);

        foreach ($entries as $entry) {
            $hit = $byNormalised[RegisterFileReader::normaliseHeader((string) $entry['header'])] ?? null;

            if ($hit === null || isset($mapping[$entry['field']])) {
                continue;
            }

            $mapping[(string) $entry['field']] = $hit;

            if (isset($entry['value_map'])) {
                $valueMaps[(string) $entry['field']] = (array) $entry['value_map'];
            }
        }

        return ['mapping' => $mapping, 'value_maps' => $valueMaps];
    }

    /** The header row of the NONOX template, as one CSV line per kind. */
    public static function template(RegisterKind $kind): string
    {
        return implode(';', array_column($kind->fields(), 'label'))."\r\n";
    }
}
