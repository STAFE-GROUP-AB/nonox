<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\User;
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
 * @property string $personal_number
 * @property CarbonImmutable $birth_date
 * @property ?string $bank_clearing
 * @property ?string $bank_account
 * @property string $tax_residence
 * @property ?int $tax_table
 * @property ?int $tax_column
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
    protected $hidden = ['personal_number', 'bank_clearing', 'bank_account', 'iban', 'bic', 'emergency_contact', 'notes', 'pending_bank'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_number',
        'user_id',
        'first_name',
        'last_name',
        'personal_number',
        'birth_date',
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
        'municipality_code',
        'church_member',
        'tax_adjustment_type',
        'tax_adjustment_value',
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
            'bank_clearing' => 'encrypted',
            'bank_account' => 'encrypted',
            'iban' => 'encrypted',
            'bic' => 'encrypted',
            'tax_table' => 'integer',
            'tax_column' => 'integer',
            'church_member' => 'boolean',
            'tax_adjustment_value' => 'integer',
            'tax_adjustment_valid_from' => 'immutable_date',
            'tax_adjustment_valid_to' => 'immutable_date',
            'emergency_contact' => 'encrypted',
            'notes' => 'encrypted',
            'portal_invited_at' => 'immutable_datetime',
            'pending_bank' => 'encrypted:array',
            'pending_bank_at' => 'immutable_datetime',
        ];
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
