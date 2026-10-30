<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An input repeated in every run between two dates.
 */
class RecurringInput extends Model
{
    use BelongsToCompany;

    protected $table = 'recurring_inputs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'pay_code_id',
        'amount_ore',
        'quantity',
        'valid_from',
        'valid_to',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ];
    }
}
