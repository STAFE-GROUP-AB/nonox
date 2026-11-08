<?php

declare(strict_types=1);

namespace App\Migration\Import\Guides;

use App\Support\Instance\InstanceSettings;

/**
 * The per-program moving guides (ADR-076, section 6), read from
 * docs/migration/flyttguider.json. The pages are built behind an instance
 * setting that is off until the legal review ADR-020 asks for is done.
 *
 * A step is shown only where the vendor itself documents it: a capability
 * whose evidence is not "vendor" never shows steps, only that nothing is
 * documented publicly. Programs are named in data, never in code.
 */
final class FlyttGuider
{
    public const string FILE = 'docs/migration/flyttguider.json';

    public const array CAPABILITIES = [
        'sie4' => 'Bokföring (SIE4)',
        'customers' => 'Kunder',
        'suppliers' => 'Leverantörer',
        'articles' => 'Artiklar',
        'open_invoices' => 'Obetalda fakturor',
        'attachments' => 'Underlag',
    ];

    /** Whether the pages are published on this instance. */
    public static function enabled(): bool
    {
        return InstanceSettings::current()->migrationGuidesPublic();
    }

    /** @return array<string, mixed> */
    public static function data(?string $path = null): array
    {
        $decoded = json_decode((string) file_get_contents($path ?? base_path(self::FILE)), true);

        return is_array($decoded) ? $decoded : ['programs' => []];
    }

    /** @return list<array<string, mixed>> */
    public static function programs(?string $path = null): array
    {
        return array_values(array_map(self::prepare(...), (array) (self::data($path)['programs'] ?? [])));
    }

    /** @return ?array<string, mixed> */
    public static function find(string $slug, ?string $path = null): ?array
    {
        foreach (self::programs($path) as $program) {
            if ($program['slug'] === $slug) {
                return $program;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function forbiddenStrings(?string $path = null): array
    {
        return array_values(array_map(strval(...), (array) (self::data($path)['forbidden_strings'] ?? [])));
    }

    /**
     * Fills in every capability, so a page lists all six and says
     * "dokumenteras inte offentligt" for those the vendor does not document,
     * and drops steps that do not come from the vendor or lack a source.
     *
     * @param  array<string, mixed>  $program
     * @return array<string, mixed>
     */
    private static function prepare(array $program): array
    {
        $given = [];

        foreach ((array) ($program['capabilities'] ?? []) as $capability) {
            $given[(string) $capability['key']] = $capability;
        }

        $capabilities = [];

        foreach (self::CAPABILITIES as $key => $label) {
            $capability = $given[$key] ?? ['key' => $key, 'evidence' => 'none', 'steps' => []];
            $vendor = ($capability['evidence'] ?? 'none') === 'vendor';

            $steps = $vendor
                ? array_values(array_filter((array) ($capability['steps'] ?? []), fn (array $step): bool => str_starts_with((string) ($step['source_url'] ?? ''), 'https://') && ($step['read_date'] ?? '') !== '' && ($step['text'] ?? '') !== ''))
                : [];

            $capabilities[] = [
                'key' => $key,
                'label' => $label,
                'documented' => $steps !== [],
                'steps' => $steps,
                'note' => (string) ($capability['note'] ?? ''),
            ];
        }

        return [...$program, 'capabilities' => $capabilities];
    }
}
