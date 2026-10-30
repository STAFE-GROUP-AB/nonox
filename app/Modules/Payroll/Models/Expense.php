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
 * An outlay, per diem or mileage claim, repaid through payroll.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $type
 * @property CarbonImmutable $date
 * @property Money $amount_ore
 * @property Money $vat_ore
 * @property ?int $account_id
 * @property ?string $receipt_path
 * @property ?string $receipt_name
 * @property ?string $receipt_mime
 * @property ?string $description
 * @property string $status
 * @property ?int $payroll_run_id
 * @property ?int $requested_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $rejection_reason
 * @property ?string $voucher_id
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
        'description',
        'receipt_name',
        'receipt_mime',
        'requested_by',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'voucher_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'trip_data' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
