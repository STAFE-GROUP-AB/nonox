<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company or person the business deals with, prospect or customer
 * (ADR-043). A customer's ledger fields stay on customers; the contact
 * fields shared with the customer (name, numbers, e-mail, phone, address)
 * follow the customer register while the account is linked to one.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $customer_id
 * @property string $name
 * @property ?string $org_number
 * @property AccountKind $kind
 * @property AccountStatus $status
 * @property ?CarbonImmutable $status_set_manually_at
 * @property ?string $lost_reason
 * @property ?int $owner_id
 * @property ?int $parent_id
 * @property ?string $sni_code
 * @property ?string $website
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $address_line1
 * @property ?string $postal_code
 * @property ?string $city
 * @property string $country
 * @property ?string $source
 * @property list<string> $tags
 * @property ?CarbonImmutable $last_activity_at
 * @property ?CarbonImmutable $win_back_at a lost account: when to try again (ADR-043 stage 2)
 * @property ?CarbonImmutable $win_back_task_at when the "Vinn tillbaka" task was made
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read AccountPerson $link the person's place here, when read through Person::accounts()
 */
class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_accounts';

    /** The fields an account shares with its customer: the customer register is the source. */
    public const array CUSTOMER_FIELDS = ['name', 'org_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'customer_id',
        'name',
        'org_number',
        'kind',
        'status',
        'status_set_manually_at',
        'lost_reason',
        'owner_id',
        'parent_id',
        'sni_code',
        'website',
        'email',
        'phone',
        'address_line1',
        'postal_code',
        'city',
        'country',
        'source',
        'tags',
        'last_activity_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'COMPANY',
        'status' => 'PROSPECT',
        'country' => 'SE',
        'tags' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => AccountKind::class,
            'status' => AccountStatus::class,
            'status_set_manually_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'win_back_at' => 'immutable_date',
            'win_back_task_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'tags' => 'array',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsToMany<Person, $this, AccountPerson, 'link'> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'crm_account_person', 'account_id', 'person_id')
            ->using(AccountPerson::class)
            ->as('link')
            ->withPivot(['id', 'role']);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function isCustomer(): bool
    {
        return $this->customer_id !== null;
    }
}
