<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A taxable benefit (car, fuel, meal, housing, other) valid between two dates.
 */
class Benefit extends Model
{
    use BelongsToCompany;

    protected $table = 'benefits';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'value_rule',
        'valid_from',
        'valid_to',
        'car_data',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_rule' => 'array',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'car_data' => 'array',
        ];
    }
}
