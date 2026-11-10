<?php

declare(strict_types=1);

use App\Domain\Units\UnitCatalogue;
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
            UnitCatalogue::seed((int) $companyId);
        }

        foreach (DB::table('articles')->select('company_id', 'unit')->distinct()->get() as $group) {
            $unitId = UnitCatalogue::resolveId((int) $group->company_id, (string) $group->unit);

            DB::table('articles')->where('company_id', $group->company_id)->where('unit', $group->unit)->update([
                'stock_unit_id' => $unitId,
                'purchase_unit_id' => $unitId,
                'sales_unit_id' => $unitId,
            ]);
        }
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? max($a, 1) : self::gcd($b, $a % $b);
    }
};
