<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A work schedule: hours per weekday, possibly over rotating weeks.
 *
 * @property string $name
 * @property array{weeks?: list<list<string|int>>} $pattern
 */
class WorkSchedule extends Model
{
    use BelongsToCompany;

    protected $table = 'work_schedules';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'pattern',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pattern' => 'array',
        ];
    }
}
