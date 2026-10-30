<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An input to a payroll run for one employee and pay code.
 */
class PayrollInput extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_inputs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'employee_id',
        'pay_code_id',
        'date_from',
        'date_to',
        'quantity',
        'rate_ore',
        'amount_ore',
        'note',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'rate_ore' => MoneyCast::class,
            'amount_ore' => MoneyCast::class,
        ];
    }
}
