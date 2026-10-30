<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payslip of one employment in one run.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property int $employment_id
 * @property Money $gross_ore
 * @property Money $tax_ore
 * @property Money $net_ore
 * @property Money $employer_contribution_ore
 * @property array<string, int> $accumulator_deltas
 * @property list<string> $warnings
 */
class Payslip extends Model
{
    use BelongsToCompany;

    protected $table = 'payslips';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'employee_id',
        'employment_id',
        'gross_ore',
        'taxable_ore',
        'tax_ore',
        'net_ore',
        'contribution_basis_ore',
        'employer_contribution_ore',
        'pdf_path',
        'published_at',
        'hash',
        'accumulator_deltas',
        'tax_explanation',
        'contribution_explanation',
        'warnings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_ore' => MoneyCast::class,
            'taxable_ore' => MoneyCast::class,
            'tax_ore' => MoneyCast::class,
            'net_ore' => MoneyCast::class,
            'contribution_basis_ore' => MoneyCast::class,
            'employer_contribution_ore' => MoneyCast::class,
            'published_at' => 'immutable_datetime',
            'accumulator_deltas' => 'array',
            'tax_explanation' => 'array',
            'contribution_explanation' => 'array',
            'warnings' => 'array',
        ];
    }

    /** @return HasMany<PayslipLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    /** @return BelongsTo<Employment, $this> */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
