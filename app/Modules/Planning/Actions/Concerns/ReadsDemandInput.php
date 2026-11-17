<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions\Concerns;

use App\Models\Company;
use App\Support\Money\Money;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Shared input rules of the demand actions: the scope (an area of the
 * company, or null for the whole company), amounts in kronor and clock
 * times.
 */
trait ReadsDemandInput
{
    /** @return list<mixed> */
    private function areaRule(Company $company): array
    {
        return ['nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)];
    }

    /** Kronor as typed ("1 200", "1200,50") to öre; null when empty or unreadable. */
    private static function ore(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** "HH:MM" to minutes after midnight; "24:00" is the end of the day. */
    private static function minute(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $parts) !== 1) {
            return null;
        }

        $minute = (int) $parts[1] * 60 + (int) $parts[2];

        return (int) $parts[2] < 60 && $minute <= 1440 ? $minute : null;
    }
}
