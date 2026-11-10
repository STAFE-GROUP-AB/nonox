<?php

declare(strict_types=1);

namespace App\Domain\Units;

use App\Support\Peppol\UblInvoiceWriter;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a company's unit catalogue and turns a free unit text into a unit
 * (ADR-071 §12.1, §12.2). Plain queries on purpose: the migration that
 * introduced the catalogue uses it too, outside any company context.
 */
final class UnitCatalogue
{
    /** Adds the default units a company does not have yet; safe to call again. */
    public static function seed(int $companyId): void
    {
        $have = DB::table('units')->where('company_id', $companyId)->pluck('code')->map(fn (string $code): string => mb_strtolower($code))->all();
        $now = now();
        $rows = [];

        foreach (UnitDefaults::ALL as $code => [$name, $dimension, $numerator, $denominator, $rec20, $aliases]) {
            if (in_array($code, $have, true)) {
                continue;
            }

            $rows[] = [
                'company_id' => $companyId,
                'code' => $code,
                'name' => $name,
                'dimension' => $dimension->value,
                'base_numerator' => $numerator,
                'base_denominator' => $denominator,
                'rec20_code' => $rec20,
                'aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('units')->insert($rows);
        }

        self::ensureRowDefault($companyId);
    }

    /** Styck (or, without it, the first active unit) as the unit of new rows (§12.9), unless one is marked. */
    public static function ensureRowDefault(int $companyId): void
    {
        if (DB::table('units')->where('company_id', $companyId)->where('row_default', true)->exists()) {
            return;
        }

        $id = DB::table('units')->where('company_id', $companyId)->whereNull('archived_at')->where('code', 'st')->value('id')
            ?? DB::table('units')->where('company_id', $companyId)->whereNull('archived_at')->orderBy('id')->value('id');

        if ($id !== null) {
            DB::table('units')->where('id', $id)->update(['row_default' => true]);
        }
    }

    private static int $adding = 0;

    /**
     * Runs $callback with rows allowed to add the unit spellings they bring
     * to the register (HasRegisterUnit): for imports of rows, never for
     * screens or the API.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function addingUnits(callable $callback): mixed
    {
        self::$adding++;

        try {
            return $callback();
        } finally {
            self::$adding--;
        }
    }

    public static function addsUnits(): bool
    {
        return self::$adding > 0;
    }

    /**
     * The id of the unit a text names (see find()). A text that names none
     * becomes a unit of the company with that code, dimension OTHER and,
     * when Peppol knows the text, its Rec 20 code (F-U7). An empty text
     * means styck.
     */
    public static function resolveId(int $companyId, string $text): int
    {
        return self::resolve($companyId, $text)['id'];
    }

    /**
     * As resolveId, and whether the unit was added to the register just now,
     * so an import can report the units it created (ADR-071 §12.9). Two
     * imports adding the same spelling at once get the same unit.
     *
     * @return array{id: int, code: string, created: bool}
     */
    public static function resolve(int $companyId, string $text): array
    {
        $found = self::find($companyId, $text);

        if ($found !== null) {
            return [...$found, 'created' => false];
        }

        $text = trim($text);
        $code = mb_substr($text, 0, 10);
        $now = now();

        $inserted = DB::table('units')->insertOrIgnore([
            'company_id' => $companyId,
            'code' => $code,
            'name' => mb_substr($text, 0, 60),
            'dimension' => UnitDimension::Other->value,
            'base_numerator' => null,
            'base_denominator' => null,
            'rec20_code' => UblInvoiceWriter::UNITS[UnitDefaults::normalise($text)] ?? null,
            'aliases' => '[]',
            'is_default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) DB::table('units')->where('company_id', $companyId)->where('code', $code)->value('id');

        return ['id' => $id, 'code' => $code, 'created' => $inserted > 0];
    }

    /**
     * The unit of the company a text names, without adding one: its code,
     * an alias or its name, case-insensitive, with one trailing full stop
     * ignored ("st.", "Kilogram", "kvadratmeter"). A longer text stored cut
     * to the code's ten characters names that unit again. An empty text
     * means styck.
     *
     * @return ?array{id: int, code: string}
     */
    public static function find(int $companyId, string $text): ?array
    {
        if (! DB::table('units')->where('company_id', $companyId)->exists()) {
            self::seed($companyId);
        }

        $units = DB::table('units')->where('company_id', $companyId)->orderBy('id')->get(['id', 'code', 'name', 'aliases'])
            ->map(fn (object $unit): array => [
                'id' => (int) $unit->id,
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'aliases' => is_array($aliases = json_decode((string) $unit->aliases, true)) ? $aliases : [],
            ])->all();

        $index = self::matchIn($units, trim($text) === '' ? 'st' : $text);

        return $index === null ? null : ['id' => $units[$index]['id'], 'code' => $units[$index]['code']];
    }

    /**
     * The position of the unit a text names among $units: by code first,
     * then alias, then name (see find()).
     *
     * @param  array<int, array{code: string, name: string, aliases: array<mixed>}>  $units  aliases as stored (jsonb)
     */
    public static function matchIn(array $units, string $text): ?int
    {
        $key = UnitDefaults::normalise($text);

        if ($key === '') {
            return null;
        }

        $keys = array_values(array_unique([$key, rtrim(preg_replace('/\.$/u', '', $key) ?? $key)]));
        $cut = array_map(fn (string $one): string => mb_substr($one, 0, 10), $keys);

        foreach ([
            fn (array $unit): bool => in_array(mb_strtolower($unit['code']), [...$keys, ...$cut], true),
            fn (array $unit): bool => array_intersect($keys, $unit['aliases']) !== [],
            fn (array $unit): bool => in_array(mb_strtolower($unit['name']), $keys, true),
        ] as $test) {
            foreach ($units as $index => $unit) {
                if ($test($unit)) {
                    return $index;
                }
            }
        }

        return null;
    }
}
