<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Models;

use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Stripe customer and the NONOX customer it is linked to (ADR-080 §7.5).
 * One Stripe customer links to at most one NONOX customer; several Stripe
 * customers may link to the same one. Until it is linked, the Stripe
 * customer counts in the metrics as its own provisional customer, whose id
 * in the ledger is the negative of this row's id (provisionalId()).
 *
 * Of the Stripe customer only what matching and the pages need is kept, as
 * it appears in Stripe: name, e-mail, country, address, tax ids and the
 * nonox_customer_number metadata (minimisation, §8).
 *
 * @property int $id
 * @property int $company_id
 * @property int $connection_id
 * @property string $stripe_customer_id
 * @property ?int $customer_id
 * @property string $status
 * @property ?string $method
 * @property ?int $suggested_customer_id
 * @property ?string $suggestion_method
 * @property ?int $confirmed_by
 * @property ?CarbonImmutable $confirmed_at
 * @property ?string $name
 * @property ?string $email
 * @property ?string $country
 * @property ?array<string, ?string> $address
 * @property ?list<array{type: string, value: string, country: ?string}> $tax_ids
 * @property ?string $nonox_customer_number
 * @property bool $deleted_in_stripe
 * @property ?CarbonImmutable $erased_at
 */
class StripeCustomerLink extends Model
{
    use BelongsToCompany;

    public const string LINKED = 'LINKED';

    /** A candidate was found by e-mail or by name, waiting for a person. */
    public const string SUGGESTED = 'SUGGESTED';

    public const string UNLINKED = 'UNLINKED';

    /** metadata.nonox_customer_number named a customer of this company (rule 1). */
    public const string BY_METADATA = 'METADATA';

    /** A VAT or organisation number in the tax ids (rule 2). */
    public const string BY_TAX_ID = 'TAX_ID';

    /** The e-mail address equals exactly one customer's (rule 3, a suggestion). */
    public const string BY_EMAIL = 'EMAIL';

    /** The name and the country (rule 4, a suggestion). */
    public const string BY_NAME = 'NAME';

    public const string BY_PERSON = 'MANUAL';

    /** "Skapa kund": a NONOX customer made from the Stripe customer. */
    public const string BY_CREATED = 'CREATED';

    /** The tax id types matching and "Skapa kund" use; others are not kept (minimisation, review C4). */
    public const array TAX_ID_TYPES = ['eu_vat', 'gb_vat', 'no_vat', 'ch_vat'];

    /** What erasure clears: everything but the id, the country and the link to the customer register. */
    public const array ERASED_FIELDS = ['name' => null, 'email' => null, 'address' => null, 'tax_ids' => null, 'nonox_customer_number' => null];

    protected $table = 'stripe_customer_links';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'connection_id',
        'stripe_customer_id',
        'customer_id',
        'status',
        'method',
        'suggested_customer_id',
        'suggestion_method',
        'confirmed_by',
        'confirmed_at',
        'name',
        'email',
        'country',
        'address',
        'tax_ids',
        'nonox_customer_number',
        'deleted_in_stripe',
        'erased_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'customer_id' => 'integer',
            'suggested_customer_id' => 'integer',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'address' => 'array',
            'tax_ids' => 'array',
            'deleted_in_stripe' => 'boolean',
            'erased_at' => 'immutable_datetime',
        ];
    }

    /** The id the ledger counts this Stripe customer under while it is not linked. */
    public function provisionalId(): int
    {
        return -$this->id;
    }

    /** The customer id its subscriptions are counted under now. */
    public function ledgerCustomerId(): int
    {
        return $this->customer_id ?? $this->provisionalId();
    }

    public function isLinked(): bool
    {
        return $this->status === self::LINKED && $this->customer_id !== null;
    }

    /** Linked by a rule and not yet looked at by a person: listed for review (§7.5). */
    public function needsReview(): bool
    {
        return $this->isLinked() && $this->confirmed_at === null;
    }

    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    public function label(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->stripe_customer_id;
    }

    /**
     * Only Stripe customers with at least one subscription: the ones that
     * count in the metrics. One subquery, no extra query per row.
     *
     * @param  Builder<StripeCustomerLink>  $query
     * @return Builder<StripeCustomerLink>
     */
    public function scopeWithSubscriptions(Builder $query): Builder
    {
        return $query->whereExists(fn ($exists) => $exists
            ->selectRaw('1')
            ->from('saas_contract_versions')
            ->whereColumn('saas_contract_versions.company_id', 'stripe_customer_links.company_id')
            ->where('saas_contract_versions.source_type', 'STRIPE_SUBSCRIPTION')
            ->whereRaw("saas_contract_versions.source_ref->>'connection' = stripe_customer_links.connection_id::text")
            ->whereRaw("saas_contract_versions.source_ref->>'customer' = stripe_customer_links.stripe_customer_id"));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function suggested(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'suggested_customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
