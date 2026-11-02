<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Models;

use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\StaffLedger\Domain\Identity;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who works in a location and is registered in the staff ledger:
 * own staff or hired staff (with the employer's name and organisation
 * number, or a foreign employer number and its country). The personal
 * number is encrypted at rest and found through a keyed blind index; for a
 * person without a Swedish number it holds a foreign identity number, and
 * `identity_country` the issuing country (Identity). The kiosk PIN is never stored, only a PBKDF2 verifier
 * with a per-person salt (ADR-022).
 *
 * @property int $id
 * @property int $company_id
 * @property string $first_name
 * @property string $last_name
 * @property string $personal_number
 * @property string $personal_number_index
 * @property ?string $identity_country
 * @property ?string $email
 * @property string $language
 * @property ?string $employer_name
 * @property ?string $employer_org_number
 * @property ?string $employer_country
 * @property ?int $employee_id
 * @property ?int $user_id
 * @property ?string $photo_path
 * @property ?CarbonImmutable $photo_consent_at
 * @property ?string $pin_salt
 * @property ?string $pin_verifier
 * @property ?string $control_salt
 * @property ?string $control_key
 * @property ?string $qr_secret_hash
 * @property ?string $qr_nonce
 * @property bool $is_manager
 * @property ?CarbonImmutable $archived_at
 */
class Person extends Model
{
    use BelongsToCompany;

    protected $table = 'staff_ledger_people';

    /** @var list<string> */
    protected $hidden = ['personal_number', 'personal_number_index', 'pin_salt', 'pin_verifier', 'control_salt', 'control_key', 'qr_secret_hash', 'qr_nonce'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'personal_number',
        'personal_number_index',
        'identity_country',
        'email',
        'language',
        'employer_name',
        'employer_org_number',
        'employer_country',
        'employee_id',
        'user_id',
        'is_manager',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_number' => 'encrypted',
            'control_key' => 'encrypted',
            'photo_consent_at' => 'immutable_datetime',
            'is_manager' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** A keyed hash of the twelve digits (or Identity::indexKey()), so duplicates are found without decrypting. */
    public static function indexFor(string $digits): string
    {
        return hash_hmac('sha256', 'staff-ledger-person:'.$digits, (string) config('app.key'));
    }

    public function identity(): Identity
    {
        return new Identity($this->personal_number, $this->identity_country);
    }

    public function employerLabel(): string
    {
        return Identity::employer($this->employer_name, $this->employer_org_number, $this->employer_country);
    }

    public function name(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function maskedPersonalNumber(): string
    {
        return $this->identity()->masked();
    }

    public function formattedPersonalNumber(): string
    {
        return $this->identity()->formatted();
    }

    public function hasPin(): bool
    {
        return $this->pin_verifier !== null;
    }

    public function isHired(): bool
    {
        return $this->employer_org_number !== null;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
