<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An outlay, per diem or mileage claim, repaid through payroll.
 */
class Expense extends Model
{
    use BelongsToCompany;

    protected $table = 'expenses';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'date',
        'amount_ore',
        'vat_ore',
        'account_id',
        'receipt_path',
        'trip_data',
        'status',
        'payroll_run_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'trip_data' => 'array',
        ];
    }
}
