<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Models\Customer;
use App\Modules\Portal\Domain\ContactStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An identity's access to one customer's portal in one company (ADR-075
 * §2). A person at a group can be a contact for several customers of the
 * same company and sees each only in its own portal.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property int $portal_identity_id
 * @property ?int $crm_person_id
 * @property ContactStatus $status
 * @property bool $can_accept_quotes
 * @property bool $can_order
 * @property ?int $invited_by
 * @property ?CarbonImmutable $invited_at
 * @property ?CarbonImmutable $accepted_at
 * @property ?int $disabled_by
 * @property ?CarbonImmutable $disabled_at
 * @property ?CarbonImmutable $last_seen_at
 * @property ?CarbonImmutable $inactivity_notified_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class PortalContact extends Model
{
    use BelongsToCompany;

    protected $table = 'customer_portal_contacts';

    /** @var list<string> */
    protected $fillable = ['company_id', 'customer_id', 'portal_identity_id', 'crm_person_id', 'can_accept_quotes', 'invited_by', 'invited_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'INVITED', 'can_accept_quotes' => true, 'can_order' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'can_accept_quotes' => 'boolean',
            'can_order' => 'boolean',
            'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'inactivity_notified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ContactStatus::Active;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PortalInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(PortalInvitation::class, 'contact_id');
    }

    /** @return BelongsTo<PortalIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }
}
