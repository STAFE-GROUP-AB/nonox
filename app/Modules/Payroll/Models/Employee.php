<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee. Personal number, bank details, emergency contact and notes are encrypted at rest (PAYROLL_MODULE.md B.15).
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_number
 * @property ?int $user_id
 * @property ?CarbonImmutable $portal_invited_at
 * @property string $first_name
 * @property string $last_name
 * @property ?string $email
 * @property ?string $personal_number null for a SINK or A-SINK payee without a Swedish number (#74)
 * @property CarbonImmutable $birth_date
 * @property ?string $birth_place
 * @property ?string $birth_country ISO 3166-1 alpha-2
 * @property ?string $citizenship ISO 3166-1 alpha-2
 * @property ?string $foreign_tin
 * @property ?string $foreign_tin_country ISO 3166-1 alpha-2
 * @property ?string $address
 * @property ?string $postal_code
 * @property ?string $city
 * @property string $country
 * @property ?string $bank_clearing
 * @property ?string $bank_account
 * @property string $tax_residence
 * @property ?int $tax_table
 * @property ?int $tax_column
 * @property string $withholding_basis TABLE (main income, tax table) or SIDE_INCOME (30 %, SFL 11 kap. 20 §)
 * @property ?string $activity_type verksamhetens art for A-SINK (AGI field 112)
 * @property string $tax_adjustment_type NONE, PERCENT (basis points), TABLE (table and column) or AMOUNT (öre per payment)
 * @property ?int $tax_adjustment_value
 * @property ?int $tax_adjustment_table
 * @property ?int $tax_adjustment_column
 * @property ?CarbonImmutable $tax_adjustment_valid_from
 * @property ?CarbonImmutable $tax_adjustment_valid_to
 * @property string $status
 * @property ?array{bank_clearing: ?string, bank_account: ?string} $pending_bank
 * @property ?int $pending_bank_by
 * @property ?CarbonImmutable $pending_bank_at
 */
class Employee extends Model
{
    use BelongsToCompany;

    protected $table = 'employees';

    /** Never serialised: personal number, bank details and private notes. */
    /** @var list<string> */
    protected $hidden = ['personal_number', 'foreign_tin', 'bank_clearing', 'bank_account', 'iban', 'bic', 'emergency_contact', 'notes', 'pending_bank'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_number',
        'user_id',
        'first_name',
        'last_name',
        'personal_number',
        'birth_date',
        'birth_place',
        'birth_country',
        'citizenship',
        'foreign_tin',
        'foreign_tin_country',
        'address',
        'postal_code',
        'city',
        'country',
        'email',
        'phone',
        'language',
        'bank_clearing',
        'bank_account',
        'iban',
        'bic',
        'tax_residence',
        'tax_table',
        'tax_column',
        'withholding_basis',
        'activity_type',
        'municipality_code',
        'church_member',
        'tax_adjustment_type',
        'tax_adjustment_value',
        'tax_adjustment_table',
        'tax_adjustment_column',
        'tax_adjustment_valid_from',
        'tax_adjustment_valid_to',
        'emergency_contact',
        'notes',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_number' => 'integer',
            'personal_number' => 'encrypted',
            'birth_date' => 'immutable_date',
            'foreign_tin' => 'encrypted',
            'bank_clearing' => 'encrypted',
            'bank_account' => 'encrypted',
            'iban' => 'encrypted',
            'bic' => 'encrypted',
            'tax_table' => 'integer',
            'tax_column' => 'integer',
            'church_member' => 'boolean',
            'tax_adjustment_value' => 'integer',
            'tax_adjustment_table' => 'integer',
            'tax_adjustment_column' => 'integer',
            'tax_adjustment_valid_from' => 'immutable_date',
            'tax_adjustment_valid_to' => 'immutable_date',
            'emergency_contact' => 'encrypted',
            'notes' => 'encrypted',
            'portal_invited_at' => 'immutable_datetime',
            'pending_bank' => 'encrypted:array',
            'pending_bank_at' => 'immutable_datetime',
        ];
    }

    /**
     * The jämkning decision in force on a payment date, or null: the payer
     * follows Skatteverket's decision on a särskild beräkningsgrund for the
     * payments its validity covers (SFL 11 kap. 27 §).
     */
    public function taxAdjustmentOn(CarbonImmutable $paymentDate): ?string
    {
        if ($this->tax_adjustment_type === 'NONE' || $this->tax_adjustment_valid_from === null) {
            return null;
        }

        if ($paymentDate->lessThan($this->tax_adjustment_valid_from)) {
            return null;
        }

        if ($this->tax_adjustment_valid_to !== null && $paymentDate->greaterThan($this->tax_adjustment_valid_to)) {
            return null;
        }

        return $this->tax_adjustment_type;
    }

    /** Whether the employee has a Swedish personal or coordination number; otherwise AGI uses the birth date (#74). */
    public function hasSwedishNumber(): bool
    {
        return filled($this->personal_number);
    }

    /** The personal number masked for lists and payslips, or the birth date when there is none. */
    public function maskedIdentity(): string
    {
        return $this->hasSwedishNumber()
            ? PersonalNumber::masked((string) $this->personal_number)
            : __('född :date', ['date' => $this->birth_date->toDateString()]);
    }

    public function hasPendingBankChange(): bool
    {
        return $this->pending_bank !== null;
    }

    /** @return HasMany<Employment, $this> */
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether an employment is still running today (none ended, or the last day not yet passed). */
    public function isEmployedToday(): bool
    {
        return $this->employments()
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()))
            ->exists();
    }
}
