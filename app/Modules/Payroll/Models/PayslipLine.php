<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\CostCenter;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a payslip, with the rule version and the explanation that produced it.
 *
 * @property int $id
 * @property int $payslip_id
 * @property int $pay_code_id
 * @property string $description
 * @property ?string $quantity
 * @property ?Money $rate_ore
 * @property Money $amount_ore
 * @property string $tax_effect
 * @property array<string, mixed> $explanation
 * @property ?int $cost_center_id from the time report the line prices; null: the employment's cost centre
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
        'cost_center_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_ore' => MoneyCast::class,
            'amount_ore' => MoneyCast::class,
            'explanation' => 'array',
            'cost_center_id' => 'integer',
        ];
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
