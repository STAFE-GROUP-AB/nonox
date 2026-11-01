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
 * One transaction from a payroll file. PROPOSED until a person approves it
 * (it then becomes a payroll input, payroll_input_id) or rejects it; SKIPPED
 * when its code is left out, or it is absence or a balance, which a file
 * import never pays. A proposed line with problems cannot be approved.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_file_import_id
 * @property int $line_no
 * @property string $section
 * @property ?string $employee_ref
 * @property ?string $person_ref
 * @property ?int $employee_id
 * @property ?string $external_code
 * @property ?string $description
 * @property ?CarbonImmutable $date_from
 * @property ?CarbonImmutable $date_to
 * @property ?string $quantity
 * @property ?Money $rate_ore
 * @property ?Money $amount_ore
 * @property ?int $pay_code_id
 * @property string $status
 * @property list<string> $problems
 * @property list<string> $warnings
 * @property ?int $payroll_input_id
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 */
class PayrollFileImportLine extends Model
{
    use BelongsToCompany;

    public const string PROPOSED = 'PROPOSED';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string SKIPPED = 'SKIPPED';

    protected $table = 'payroll_file_import_lines';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'payroll_file_import_id', 'line_no', 'section', 'employee_ref', 'person_ref', 'employee_id',
        'external_code', 'description', 'date_from', 'date_to', 'quantity', 'rate_ore', 'amount_ore', 'pay_code_id',
        'status', 'problems', 'warnings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'rate_ore' => MoneyCast::class,
            'amount_ore' => MoneyCast::class,
            'problems' => 'array',
            'warnings' => 'array',
            'decided_at' => 'immutable_datetime',
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

    public function canBeApproved(): bool
    {
        return $this->status === self::PROPOSED && $this->problems === [] && $this->employee_id !== null && $this->pay_code_id !== null;
    }
}
