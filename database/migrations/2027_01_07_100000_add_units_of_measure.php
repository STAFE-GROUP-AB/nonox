<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Units of measure on articles (ADR-071 §12). A unit catalogue per company,
 * a stock, purchase and sales unit per article, the material's nominal size
 * for derived conversions, and conversions as exact fractions. Existing
 * articles keep their unit text and get the matching catalogue unit as all
 * three units, so nothing a company sees changes. No stock movement is
 * written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 60);
            $table->string('dimension', 20);
            // One of this unit is numerator/denominator of the dimension's base unit; null = no fixed factor.
            $table->bigInteger('base_numerator')->nullable();
            $table->bigInteger('base_denominator')->nullable();
            $table->string('rec20_code', 3)->nullable();
            $table->jsonb('aliases')->default('[]');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        DB::statement('ALTER TABLE units ADD CONSTRAINT units_base_positive CHECK ((base_numerator IS NULL AND base_denominator IS NULL) OR (base_numerator > 0 AND base_denominator > 0))');

        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('stock_unit_id')->nullable()->constrained('units');
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units');
            $table->foreignId('sales_unit_id')->nullable()->constrained('units');
            // Nominal size in micrometres (0,6 mm veneer is 600); separate from the package size length_mm, width_mm, height_mm.
            $table->bigInteger('material_length_um')->nullable();
            $table->bigInteger('material_width_um')->nullable();
            $table->bigInteger('material_thickness_um')->nullable();
        });

        Schema::table('article_unit_conversions', function (Blueprint $table) {
            // One stock unit is numerator/denominator of this unit, stored reduced.
            $table->bigInteger('numerator')->nullable();
            $table->bigInteger('denominator')->nullable();
            $table->string('source', 12)->default('MANUAL');
        });

        DB::statement('ALTER TABLE article_unit_conversions DROP CONSTRAINT article_unit_conversions_factor_positive');
        DB::statement('ALTER TABLE article_unit_conversions ALTER COLUMN factor_milli DROP NOT NULL');

        foreach (DB::table('article_unit_conversions')->get(['id', 'factor_milli']) as $row) {
            $gcd = self::gcd((int) $row->factor_milli, 1000);

            DB::table('article_unit_conversions')->where('id', $row->id)->update([
                'numerator' => intdiv((int) $row->factor_milli, $gcd),
                'denominator' => intdiv(1000, $gcd),
            ]);
        }

        DB::statement('ALTER TABLE article_unit_conversions ALTER COLUMN numerator SET NOT NULL');
        DB::statement('ALTER TABLE article_unit_conversions ALTER COLUMN denominator SET NOT NULL');
        DB::statement('ALTER TABLE article_unit_conversions ADD CONSTRAINT article_unit_conversions_fraction_positive CHECK (numerator > 0 AND denominator > 0)');
        DB::statement("ALTER TABLE article_unit_conversions ADD CONSTRAINT article_unit_conversions_source_known CHECK (source IN ('MANUAL', 'DIMENSIONS'))");

        foreach (DB::table('teams')->pluck('id') as $companyId) {
            self::seed((int) $companyId);
        }

        foreach (DB::table('articles')->select('company_id', 'unit')->distinct()->get() as $group) {
            $unitId = self::resolveId((int) $group->company_id, (string) $group->unit);

            DB::table('articles')->where('company_id', $group->company_id)->where('unit', $group->unit)->update([
                'stock_unit_id' => $unitId,
                'purchase_unit_id' => $unitId,
                'sales_unit_id' => $unitId,
            ]);
        }
    }

    /**
     * The catalogue as it was when this migration was written, frozen here so
     * later changes to the defaults in code never change what it does:
     * code => [name, dimension, base numerator, base denominator, rec20, aliases].
     */
    private const array DEFAULTS = [
        'st' => ['styck', 'COUNT', 1, 1, 'H87', ['styck', 'stk', 'pcs', 'pc']],
        'm' => ['meter', 'LENGTH', 1, 1, 'MTR', ['meter']],
        'lpm' => ['löpmeter', 'LENGTH', 1, 1, 'MTR', ['löpmeter', 'lopmeter', 'löpm']],
        'm²' => ['kvadratmeter', 'AREA', 1, 1, 'MTK', ['m2', 'kvm']],
        'm³' => ['kubikmeter', 'VOLUME', 1, 1, 'MTQ', ['m3']],
        'kg' => ['kilogram', 'MASS', 1, 1, 'KGM', []],
        'g' => ['gram', 'MASS', 1, 1000, 'GRM', []],
        'l' => ['liter', 'VOLUME_LIQUID', 1, 1000, 'LTR', ['liter']],
        'h' => ['timme', 'TIME', 1, 1, 'HUR', ['tim', 'timme', 'timmar', 'hour']],
        'förp' => ['förpackning', 'COUNT', null, null, 'XPK', ['paket']],
        'par' => ['par', 'COUNT', null, null, 'PR', []],
        'set' => ['set', 'COUNT', null, null, 'SET', []],
        'mm' => ['millimeter', 'LENGTH', 1, 1000, 'MMT', []],
        'cm' => ['centimeter', 'LENGTH', 1, 100, 'CMT', []],
        'dm²' => ['kvadratdecimeter', 'AREA', 1, 100, null, ['dm2']],
        'dl' => ['deciliter', 'VOLUME_LIQUID', 1, 10_000, null, []],
        'cl' => ['centiliter', 'VOLUME_LIQUID', 1, 100_000, null, []],
        'ml' => ['milliliter', 'VOLUME_LIQUID', 1, 1_000_000, null, []],
        'min' => ['minut', 'TIME', 1, 60, 'MIN', []],
    ];

    /** The Rec 20 codes Peppol knew for unit texts at the time (UblInvoiceWriter::UNITS). */
    private const array REC20 = [
        'st' => 'H87', 'styck' => 'H87', 'stk' => 'H87', 'pcs' => 'H87', 'pc' => 'H87',
        'tim' => 'HUR', 'timme' => 'HUR', 'timmar' => 'HUR', 'h' => 'HUR', 'hour' => 'HUR',
        'min' => 'MIN', 'dag' => 'DAY', 'dagar' => 'DAY', 'dygn' => 'DAY', 'vecka' => 'WEE', 'v' => 'WEE',
        'mån' => 'MON', 'månad' => 'MON', 'mån.' => 'MON', 'år' => 'ANN',
        'kg' => 'KGM', 'g' => 'GRM', 'ton' => 'TNE', 't' => 'TNE',
        'm' => 'MTR', 'meter' => 'MTR', 'km' => 'KMT', 'cm' => 'CMT', 'mm' => 'MMT', 'mil' => 'KMT',
        'm2' => 'MTK', 'm²' => 'MTK', 'kvm' => 'MTK', 'm3' => 'MTQ', 'm³' => 'MTQ',
        'l' => 'LTR', 'liter' => 'LTR', 'kwh' => 'KWH', 'paket' => 'XPK', 'förp' => 'XPK', 'par' => 'PR', 'set' => 'SET',
    ];

    private static function seed(int $companyId): void
    {
        $have = DB::table('units')->where('company_id', $companyId)->pluck('code')->map(fn (string $code): string => mb_strtolower($code))->all();
        $now = now();
        $rows = [];

        foreach (self::DEFAULTS as $code => [$name, $dimension, $numerator, $denominator, $rec20, $aliases]) {
            if (in_array($code, $have, true)) {
                continue;
            }

            $rows[] = [
                'company_id' => $companyId, 'code' => $code, 'name' => $name, 'dimension' => $dimension,
                'base_numerator' => $numerator, 'base_denominator' => $denominator, 'rec20_code' => $rec20,
                'aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE), 'is_default' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('units')->insert($rows);
        }
    }

    /** The id of the unit a text names (code or alias, any case), or a new unit of the company with dimension OTHER (F-U7). */
    private static function resolveId(int $companyId, string $text): int
    {
        $text = trim($text);
        $key = mb_strtolower($text);
        $key = $key === '' ? 'st' : $key;

        self::seed($companyId);

        foreach (DB::table('units')->where('company_id', $companyId)->get(['id', 'code', 'aliases']) as $unit) {
            $aliases = json_decode((string) $unit->aliases, true);

            if (mb_strtolower($unit->code) === $key || in_array($key, is_array($aliases) ? $aliases : [], true)) {
                return (int) $unit->id;
            }
        }

        $now = now();

        return (int) DB::table('units')->insertGetId([
            'company_id' => $companyId, 'code' => mb_substr($text === '' ? 'st' : $text, 0, 10), 'name' => mb_substr($text === '' ? 'st' : $text, 0, 60),
            'dimension' => 'OTHER', 'base_numerator' => null, 'base_denominator' => null, 'rec20_code' => self::REC20[$key] ?? null,
            'aliases' => '[]', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? max($a, 1) : self::gcd($b, $a % $b);
    }
};
