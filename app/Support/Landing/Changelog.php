<?php

declare(strict_types=1);

namespace App\Support\Landing;

use Carbon\CarbonImmutable;

/**
 * The public changelog at /andringslogg: everything that has shipped, in
 * plain Swedish, for people who never open the code repository. The entries
 * live in docs/andringslogg/andringslogg.json, versioned with the code they
 * describe; every shipped feat/fix/security change adds one (CONTRIBUTING.md),
 * and ChangelogTest fails when the file falls behind the git history.
 *
 * @phpstan-type Entry array{id: string, datum: string, rubrik: string, beskrivning: string, typ: string, omrade: string, version?: string}
 */
final class Changelog
{
    public const string FILE = 'docs/andringslogg/andringslogg.json';

    public const string TIMEZONE = 'Europe/Stockholm';

    /** Query-string slug => type shown on the page. */
    public const array TYPES = [
        'nytt' => 'Nytt',
        'forbattrat' => 'Förbättrat',
        'rattat' => 'Rättat',
        'sakerhet' => 'Säkerhet',
    ];

    /** Query-string slug => area shown on the page. */
    public const array AREAS = [
        'bokforing' => 'Bokföring',
        'fakturering' => 'Fakturering',
        'leverantorer' => 'Leverantörer',
        'moms' => 'Moms',
        'bokslut' => 'Bokslut',
        'lon' => 'Lön',
        'avtal' => 'Avtal',
        'planering' => 'Planering',
        'personalliggare' => 'Personalliggare',
        'bank' => 'Bank',
        'connect-api' => 'Connect/API',
        'manual' => 'Manual',
        'webbplats' => 'Webbplats',
        'ovrigt' => 'Övrigt',
    ];

    /** @var list<Entry>|null */
    private static ?array $entries = null;

    /** @return list<Entry> newest first */
    public static function all(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        /** @var list<Entry> $entries */
        $entries = self::raw();
        usort($entries, fn (array $a, array $b): int => strcmp(self::date($b)->format('c'), self::date($a)->format('c')) ?: strcmp($b['id'], $a['id']));

        return self::$entries = $entries;
    }

    /**
     * The entries matching a type slug, an area slug and a search text. An
     * unknown slug is ignored rather than showing an empty page.
     *
     * @return list<Entry>
     */
    public static function filter(?string $type, ?string $area, ?string $search): array
    {
        $typeLabel = self::TYPES[(string) $type] ?? null;
        $areaLabel = self::AREAS[(string) $area] ?? null;
        $needle = mb_strtolower(trim((string) $search));

        return array_values(array_filter(self::all(), function (array $entry) use ($typeLabel, $areaLabel, $needle): bool {
            if ($typeLabel !== null && $entry['typ'] !== $typeLabel) {
                return false;
            }

            if ($areaLabel !== null && $entry['omrade'] !== $areaLabel) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower("{$entry['rubrik']} {$entry['beskrivning']} {$entry['omrade']} {$entry['typ']}"), $needle);
        }));
    }

    /**
     * The newest entries of the given types (the roadmap's "Klart nyligen").
     *
     * @param  list<string>  $types
     * @return list<Entry>
     */
    public static function latest(int $count, array $types = ['Nytt', 'Förbättrat']): array
    {
        return array_slice(array_values(array_filter(self::all(), fn (array $entry): bool => in_array($entry['typ'], $types, true))), 0, $count);
    }

    /**
     * Entries grouped by month, keyed "2026-11", in the order given.
     *
     * @param  list<Entry>  $entries
     * @return array<string, array{label: string, entries: list<Entry>}>
     */
    public static function byMonth(array $entries): array
    {
        $months = [];

        foreach ($entries as $entry) {
            $date = self::date($entry);
            $key = $date->format('Y-m');
            $months[$key] ??= ['label' => ucfirst($date->settings(['locale' => 'sv'])->isoFormat('MMMM YYYY')), 'entries' => []];
            $months[$key]['entries'][] = $entry;
        }

        return $months;
    }

    /** @param  Entry  $entry */
    public static function date(array $entry): CarbonImmutable
    {
        return CarbonImmutable::parse($entry['datum'])->setTimezone(self::TIMEZONE);
    }

    /** The slug of an area label, for links that filter on it. */
    public static function areaSlug(string $area): string
    {
        return (string) array_search($area, self::AREAS, true);
    }

    /**
     * Everything wrong with the file: missing fields, unknown types or
     * areas, dates that are not ISO 8601 with an offset, duplicate ids.
     *
     * @return list<string>
     */
    public static function errors(?string $file = null): array
    {
        $errors = [];
        $ids = [];

        foreach (self::raw($file) as $index => $entry) {
            $label = is_string($entry['id'] ?? null) ? $entry['id'] : "#{$index}";

            foreach (['id', 'datum', 'rubrik', 'beskrivning', 'typ', 'omrade'] as $field) {
                $value = $entry[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$label}: '{$field}' saknas.";
                }
            }

            if (isset($entry['version']) && (! is_string($entry['version']) || preg_match('/^[0-9a-f]{7,12}$/', $entry['version']) !== 1)) {
                $errors[] = "{$label}: 'version' ska vara en kort commit-hash.";
            }

            if (! in_array($entry['typ'] ?? null, self::TYPES, true)) {
                $errors[] = "{$label}: okänd typ.";
            }

            if (! in_array($entry['omrade'] ?? null, self::AREAS, true)) {
                $errors[] = "{$label}: okänt område.";
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', is_string($entry['datum'] ?? null) ? $entry['datum'] : '') !== 1) {
                $errors[] = "{$label}: 'datum' ska vara ISO 8601 med tidszon, till exempel 2026-11-02T12:00:00+01:00.";
            }

            if (isset($ids[$label])) {
                $errors[] = "{$label}: id finns redan.";
            }

            $ids[$label] = true;
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> the entries as written, before any check */
    private static function raw(?string $file = null): array
    {
        /** @var array{poster: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($file ?? base_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

        return $data['poster'];
    }
}
