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
 * The entries are grouped by phase (the "faser" list in the same file): the
 * project's real stages with their dates, so a long quiet stretch between two
 * phases never shows up as a row of empty months.
 *
 * @phpstan-type Entry array{id: string, datum: string, rubrik: string, beskrivning: string, typ: string, omrade: string, version?: string}
 * @phpstan-type Phase array{id: string, namn: string, beskrivning: string, fran: string, till: string|null, kommande: bool}
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
        'utredning' => 'Utredning',
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
        'kvalitet' => 'Kvalitet',
        'ovrigt' => 'Övrigt',
    ];

    /** @var list<Entry>|null */
    private static ?array $entries = null;

    /** @var list<Phase>|null */
    private static ?array $phases = null;

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

    /**
     * The project's phases, newest first: the upcoming goal before the phase
     * in progress, and the first phase last.
     *
     * @return list<Phase>
     */
    public static function phases(): array
    {
        if (self::$phases !== null) {
            return self::$phases;
        }

        $phases = [];

        foreach (self::rawPhases() as $phase) {
            $phases[] = [
                'id' => (string) $phase['id'],
                'namn' => (string) $phase['namn'],
                'beskrivning' => (string) $phase['beskrivning'],
                'fran' => (string) $phase['fran'],
                'till' => is_string($phase['till'] ?? null) ? $phase['till'] : null,
                'kommande' => ($phase['kommande'] ?? false) === true,
            ];
        }

        usort($phases, fn (array $a, array $b): int => strcmp($b['fran'], $a['fran']));

        return self::$phases = $phases;
    }

    /**
     * The phase an entry belongs to: the one whose dates hold the entry's
     * date in Stockholm time (no end date means still going). An upcoming
     * phase holds no entries.
     *
     * @param  Entry  $entry
     * @return Phase|null
     */
    public static function phaseOf(array $entry): ?array
    {
        $day = self::date($entry)->format('Y-m-d');

        foreach (self::phases() as $phase) {
            if ($phase['kommande']) {
                continue;
            }

            if ($day >= $phase['fran'] && ($phase['till'] === null || $day <= $phase['till'])) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Entries grouped by phase and, inside a phase, by month. Only months
     * with entries get a heading. A phase without matching entries is left
     * out, except an upcoming one: the goal is always shown.
     *
     * @param  list<Entry>  $entries
     * @return list<array{phase: Phase, count: int, months: array<string, array{label: string, entries: list<Entry>}>}>
     */
    public static function byPhase(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $phase = self::phaseOf($entry);

            if ($phase !== null) {
                $grouped[$phase['id']][] = $entry;
            }
        }

        $result = [];

        foreach (self::phases() as $phase) {
            $phaseEntries = $grouped[$phase['id']] ?? [];

            if ($phaseEntries === [] && ! $phase['kommande']) {
                continue;
            }

            $result[] = ['phase' => $phase, 'count' => count($phaseEntries), 'months' => self::byMonth($phaseEntries)];
        }

        return $result;
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

        return [...$errors, ...self::phaseErrors($file)];
    }

    /**
     * Everything wrong with the phases: missing fields, dates that are not
     * plain dates, and (for the published file) entries outside every phase.
     *
     * @return list<string>
     */
    private static function phaseErrors(?string $file): array
    {
        $errors = [];
        $phases = self::rawPhases($file);

        if ($phases === []) {
            return ["'faser' saknas."];
        }

        foreach ($phases as $index => $phase) {
            $label = is_string($phase['id'] ?? null) ? "fas {$phase['id']}" : "fas #{$index}";

            foreach (['id', 'namn', 'beskrivning', 'fran'] as $field) {
                $value = $phase[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$label}: '{$field}' saknas.";
                }
            }

            foreach (['fran', 'till'] as $field) {
                $value = $phase[$field] ?? null;

                if ($value !== null && (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1)) {
                    $errors[] = "{$label}: '{$field}' ska vara ett datum, till exempel 2026-11-02.";
                }
            }
        }

        if ($file !== null || $errors !== []) {
            return $errors;
        }

        foreach (self::all() as $entry) {
            if (self::phaseOf($entry) === null) {
                $errors[] = "{$entry['id']}: datumet ligger inte i någon fas.";
            }
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

    /** @return list<array<string, mixed>> the phases as written, before any check */
    private static function rawPhases(?string $file = null): array
    {
        /** @var array{faser?: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($file ?? base_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

        return $data['faser'] ?? [];
    }
}
