<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Links rows written with a unit text to the company's unit register
 * (ADR-071 §12.9). Used by the migrations that add `unit_id`; plain
 * queries, outside any company context. Every spelling found becomes, or
 * matches, a unit of the register (UnitCatalogue::resolveId), so nothing
 * is lost; the row's text is never changed.
 */
final class UnitBackfill
{
    /** Marks styck (or, without it, the first unit) as each company's default for new rows. */
    public static function rowDefaults(): void
    {
        foreach (DB::table('teams')->pluck('id') as $companyId) {
            self::ensureRowDefault((int) $companyId);
        }
    }

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

    /**
     * @param  ?Closure(Builder): mixed  $scope  limits the rows looked at (a quote template row without unit text means the article's unit)
     * @param  bool  $update  false for append-only tables: only the register is completed
     * @param  ?Closure(Builder): mixed  $updatable  limits the rows written to (the rows of issued invoices are protected)
     */
    public static function rows(string $table, ?Closure $scope = null, bool $update = true, ?Closure $updatable = null): void
    {
        $query = fn (): Builder => $scope === null ? DB::table($table)->whereNull('unit_id') : tap(DB::table($table)->whereNull('unit_id'), $scope);
        $groups = $query()->select('company_id', 'unit')->distinct()->get();

        foreach ($groups as $group) {
            $text = (string) $group->unit;
            $unitId = UnitCatalogue::resolveId((int) $group->company_id, $text);

            if (! $update) {
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
}
