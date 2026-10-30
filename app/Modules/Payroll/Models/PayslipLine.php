<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of a payslip, with the rule version and the explanation that produced it.
 */
class PayslipLine extends Model
{
    use BelongsToCompany;

    protected $table = 'payslip_lines';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payslip_id',
        'pay_code_id',
        'description',
        'quantity',
        'rate_ore',
        'amount_ore',
        'tax_effect',
        'rule_version_id',
        'explanation',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_ore' => MoneyCast::class,
            'amount_ore' => MoneyCast::class,
            'explanation' => 'array',
        ];
    }
}
