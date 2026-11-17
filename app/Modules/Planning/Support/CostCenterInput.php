<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Models\CostCenter;
use Illuminate\Validation\ValidationException;

/**
 * A cost centre typed by its code on an area or a shift (ADR-037, gap 7;
 * codes as in ADR-009), looked up in the company. An unknown code is
 * refused, and so is an inactive one, unless it is the one already chosen:
 * what was planned on it may keep it, nothing new is put on it.
 */
final class CostCenterInput
{
    /**
     * The cost centre's id, or null for an empty code.
     *
     * @throws ValidationException
     */
    public static function resolve(Company $company, mixed $code, ?int $current, string $field): ?int
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $costCenter = CostCenter::query()->where('company_id', $company->id)->where('code', $code)->first(['id', 'code', 'is_active']);

        if ($costCenter === null) {
            throw ValidationException::withMessages([$field => __('Kostnadsstället :code finns inte.', ['code' => $code])]);
        }

        if (! $costCenter->is_active && $costCenter->id !== $current) {
            throw ValidationException::withMessages([$field => __('Kostnadsstället :code är inaktivt. Välj ett aktivt kostnadsställe.', ['code' => $code])]);
        }

        return (int) $costCenter->id;
    }

    /**
     * The default an area gives a shift: its cost centre while it is active,
     * else none (the employment's). A shift already on the area's cost
     * centre ($current) keeps it when it has become inactive.
     */
    public static function areaDefault(Company $company, ?int $areaId, ?int $current = null): ?int
    {
        if ($areaId === null) {
            return null;
        }

        $costCenter = CostCenter::query()
            ->where('company_id', $company->id)
            ->whereIn('id', fn ($query) => $query->select('cost_center_id')->from('planning_areas')->where('company_id', $company->id)->where('id', $areaId))
            ->first(['id', 'is_active']);

        if ($costCenter === null) {
            return null;
        }

        return $costCenter->is_active || (int) $costCenter->id === $current ? (int) $costCenter->id : null;
    }
}
