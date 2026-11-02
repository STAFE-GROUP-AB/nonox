<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

/**
 * Rounds worked minutes the way an agreement's rounding rule says (gap G4,
 * AgreementRules::rounding): UP_PER_STARTED_UNIT counts every started unit
 * as a whole one ("varje påbörjad halvtimme"), DOWN_TO_WHOLE_UNIT counts
 * only whole units ("endast fulla halvtimmar"). Pure.
 *
 * TODO(legal-verify): per agreement, whether the rule applies per shift,
 * per occasion or per period; NONOX applies it per shift for OB and to the
 * period total for overtime.
 */
final class TimeRounding
{
    /** @param  ?array{unit_minutes: int, method: string, source: string}  $rule */
    public static function round(int $minutes, ?array $rule): int
    {
        if ($rule === null || $minutes <= 0) {
            return max(0, $minutes);
        }

        $unit = $rule['unit_minutes'];

        return match ($rule['method']) {
            'UP_PER_STARTED_UNIT' => intdiv($minutes + $unit - 1, $unit) * $unit,
            'DOWN_TO_WHOLE_UNIT' => intdiv($minutes, $unit) * $unit,
            default => $minutes,
        };
    }
}
