<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9). Invoice, quote, order and recurring
 * invoice rows point at a unit of the company's register; their unit text
 * stays as the printed snapshot. A company marks one unit as the default
 * for new rows (styck from the start). Every unit spelling already used on
 * a row (these three tables, quote template rows and work order material,
 * whose columns their modules added) becomes, or matches, a unit of the
 * register. Rows of issued invoices are protected by their trigger and the
 * material entries are append-only: they keep only their text.
 *
 * Self-contained: the matching is a frozen copy of UnitCatalogue::find()
 * and resolve() as they were when this was written, so later changes to
 * the app cannot change what this migration does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('row_default')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX units_one_row_default ON units (company_id) WHERE row_default');

        foreach (['invoice_rows', 'sales_document_rows', 'recurring_invoice_rows'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->constrained('units');
            });
        }

        foreach (DB::table('teams')->pluck('id') as $companyId) {
            self::rowDefault((int) $companyId);
        }

        self::rows('invoice_rows', updatable: fn (Builder $query) => $query->whereIn('invoice_id', DB::table('invoices')->where('status', 'DRAFT')->select('id')));
        self::rows('sales_document_rows');
        self::rows('recurring_invoice_rows');
        // A template row without a unit means the article's sales unit: only rows with a text, or free rows, are linked.
        self::rows('crm_quote_template_rows', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNotNull('unit')->orWhereNull('article_id')));
        self::rows('work_order_material_entries', update: false);
    }

    /**
     * Links the rows of a table to the unit their text names, adding the
     * spelling to the register when it names none; the text is never
     * changed. Public so the backfill can be tested.
     *
     * @param  ?Closure(Builder): mixed  $scope  limits the rows looked at
     * @param  bool  $update  false for append-only tables: only the register is completed
     * @param  ?Closure(Builder): mixed  $updatable  limits the rows written to
     */
    public static function rows(string $table, ?Closure $scope = null, bool $update = true, ?Closure $updatable = null): void
    {
        $query = fn (): Builder => $scope === null ? DB::table($table)->whereNull('unit_id') : tap(DB::table($table)->whereNull('unit_id'), $scope);

        foreach ($query()->select('company_id', 'unit')->distinct()->get() as $group) {
            $text = (string) $group->unit;
            $unitId = self::resolve((int) $group->company_id, $text);

            if ($unitId === null || ! $update) {
                continue;
            }

            $rows = $query()->where('company_id', $group->company_id);
            $group->unit === null ? $rows->whereNull('unit') : $rows->where('unit', $text);

            if ($updatable !== null) {
                $updatable($rows);
            }

            $rows->update(['unit_id' => $unitId]);
        }
    }

    /** Styck (or, without it, the first active unit) as the unit of new rows. */
    private static function rowDefault(int $companyId): void
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

    /**
     * The unit a text names: its code (also cut to ten characters), an
     * alias or its name, any case, one trailing full stop ignored; an empty
     * text means styck. A text that names none becomes a unit with that
     * code and dimension OTHER. Null for a company without a register (none
     * exists: every company was seeded by 2027_01_07_100000).
     */
    private static function resolve(int $companyId, string $text): ?int
    {
        $units = DB::table('units')->where('company_id', $companyId)->orderBy('id')->get(['id', 'code', 'name', 'aliases']);

        if ($units->isEmpty()) {
            return null;
        }

        $text = trim($text) === '' ? 'st' : trim($text);
        $key = mb_strtolower($text);
        $keys = array_values(array_unique([$key, preg_replace('/\.$/u', '', $key) ?? $key]));
        $cut = array_map(fn (string $one): string => mb_substr($one, 0, 10), $keys);
        $aliases = fn (stdClass $unit): array => is_array($list = json_decode((string) $unit->aliases, true)) ? $list : [];

        $unit = $units->first(fn (stdClass $unit): bool => in_array(mb_strtolower((string) $unit->code), [...$keys, ...$cut], true))
            ?? $units->first(fn (stdClass $unit): bool => array_intersect($keys, $aliases($unit)) !== [])
            ?? $units->first(fn (stdClass $unit): bool => in_array(mb_strtolower((string) $unit->name), $keys, true) || in_array(mb_substr(mb_strtolower((string) $unit->name), 0, 10), $cut, true));

        if ($unit !== null) {
            return (int) $unit->id;
        }

        $code = mb_substr($text, 0, 10);
        $now = now();

        DB::table('units')->insertOrIgnore([
            'company_id' => $companyId, 'code' => $code, 'name' => mb_substr($text, 0, 60), 'dimension' => 'OTHER',
            'base_numerator' => null, 'base_denominator' => null, 'rec20_code' => self::REC20[$key] ?? null,
            'aliases' => '[]', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('units')->where('company_id', $companyId)->where('code', $code)->value('id');
    }
};
