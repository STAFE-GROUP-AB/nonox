<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A closed payroll calendar year with its totals at closing.
 *
 * @property int $id
 * @property int $company_id
 * @property int $year
 * @property array<string, int> $totals
 * @property int $closed_by
 * @property CarbonImmutable $created_at
 */
class PayrollYearClosing extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'year',
        'totals',
        'closed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'totals' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function isClosed(int $year): bool
    {
        return self::query()->where('year', $year)->exists();
    }
}
