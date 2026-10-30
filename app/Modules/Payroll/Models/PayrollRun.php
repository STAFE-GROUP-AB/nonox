<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll run for a period and payment date. Immutable once approved (P-8).
 */
class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period',
        'payment_date',
        'type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'calculated_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
