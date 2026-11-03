<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Domain\LegalBasis;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contact person (ADR-043). A person can belong to several accounts, each
 * with a role, and is kept on a stated legal basis (GDPR art. 6.1).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ?string $title
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $mobile
 * @property LegalBasis $legal_basis
 * @property ?string $legal_basis_note
 * @property ?CarbonImmutable $consent_at
 * @property bool $is_active
 * @property ?CarbonImmutable $last_activity_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read AccountPerson $link the person's place at the account, when read through Account::people()
 */
class Person extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_people';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'title',
        'email',
        'phone',
        'mobile',
        'legal_basis',
        'legal_basis_note',
        'consent_at',
        'is_active',
        'last_activity_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'legal_basis' => LegalBasis::class,
            'consent_at' => 'immutable_date',
            'is_active' => 'boolean',
            'last_activity_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsToMany<Account, $this, AccountPerson, 'link'> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'crm_account_person', 'person_id', 'account_id')
            ->using(AccountPerson::class)
            ->as('link')
            ->withPivot(['id', 'role']);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** The number to call: the mobile first. */
    public function callNumber(): ?string
    {
        return $this->mobile ?? $this->phone;
    }
}
