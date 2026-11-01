<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An input to a payroll run for one employee and pay code.
 *
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property int $pay_code_id
 * @property ?string $quantity
 * @property ?Money $rate_ore
 * @property ?Money $amount_ore
 * @property ?string $note
 * @property ?CarbonImmutable $date_from
 * @property ?CarbonImmutable $date_to
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

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PayCode, $this> */
    public function payCode(): BelongsTo
    {
        return $this->belongsTo(PayCode::class);
    }
}
