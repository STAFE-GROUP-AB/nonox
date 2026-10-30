<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Hours reported for a day and pay code.
 */
class TimeReport extends Model
{
    use BelongsToCompany;

    protected $table = 'time_reports';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'hours',
        'pay_code_id',
        'project_id',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }
}
