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
    }

    /**
     * The id of the unit a text names: its code or an alias, case-insensitive.
     * A text that names none becomes a unit of the company with that code,
     * dimension OTHER and, when Peppol knows the text, its Rec 20 code
     * (F-U7). An empty text means styck.
     */
    public static function resolveId(int $companyId, string $text): int
    {
        $text = trim($text);
        $key = UnitDefaults::normalise($text);
        $key = $key === '' ? 'st' : $key;

        if (! DB::table('units')->where('company_id', $companyId)->exists()) {
            self::seed($companyId);
        }

        foreach (DB::table('units')->where('company_id', $companyId)->get(['id', 'code', 'aliases']) as $unit) {
            $aliases = json_decode((string) $unit->aliases, true);

            if (mb_strtolower($unit->code) === $key || in_array($key, is_array($aliases) ? $aliases : [], true)) {
                return (int) $unit->id;
            }
        }

        $rec20 = UblInvoiceWriter::UNITS[$key] ?? null;
        $now = now();

        return (int) DB::table('units')->insertGetId([
            'company_id' => $companyId,
            'code' => mb_substr($text === '' ? 'st' : $text, 0, 10),
            'name' => mb_substr($text === '' ? 'st' : $text, 0, 60),
            'dimension' => UnitDimension::Other->value,
            'base_numerator' => null,
            'base_denominator' => null,
            'rec20_code' => $rec20,
            'aliases' => '[]',
            'is_default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
