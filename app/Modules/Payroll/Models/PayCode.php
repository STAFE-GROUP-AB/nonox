<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A pay code (löneart): category, tax and contribution treatment, AGI field and accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name_sv
 * @property string $category
 * @property bool $is_taxable
 * @property string $tax_method
 * @property bool $is_contribution_basis
 * @property bool $is_vacation_basis
 * @property ?int $debit_account_id
 * @property ?int $credit_account_id
 * @property bool $is_system
 * @property ?string $formula_ref
 */
class PayCode extends Model
{
    use BelongsToCompany;

    /** formula_ref of a pay code the collective agreement prices (NONOX Avtal, ADR-033): "agreement:<code>". */
    public const string AGREEMENT_PREFIX = 'agreement:';

    protected $table = 'pay_codes';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name_sv',
        'name_en',
        'category',
        'unit',
        'formula_ref',
        'is_taxable',
        'tax_method',
        'is_contribution_basis',
        'is_vacation_basis',
        'is_pension_basis',
        'agi_field_code',
        'debit_account_id',
        'credit_account_id',
        'use_employment_dimensions',
        'is_system',
        'is_active',
    ];

    /** Whether the collective agreement prices this pay code (NONOX Avtal). */
    public function isAgreementPriced(): bool
    {
        return str_starts_with((string) $this->formula_ref, self::AGREEMENT_PREFIX);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_contribution_basis' => 'boolean',
            'is_vacation_basis' => 'boolean',
            'is_pension_basis' => 'boolean',
            'use_employment_dimensions' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
