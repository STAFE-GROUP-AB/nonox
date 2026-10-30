<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A year-to-date or vacation-year total per employee and key.
 */
class Accumulator extends Model
{
    use BelongsToCompany;

    protected $table = 'accumulators';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'scope',
        'year',
        'key',
        'value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'value' => 'integer',
        ];
    }
}
