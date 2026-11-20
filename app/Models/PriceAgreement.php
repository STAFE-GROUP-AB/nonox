<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's negotiated prices, "Prisavtal" in the interface (ADR-082 §4),
 * numbered PA-1, PA-2 … per company. Activation, signing, freezing of an
 * active agreement's items and versions are built in P5.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $customer_id
 * @property string $title
 * @property string $currency
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property PriceAgreementStatus $status
 * @property ?int $crm_agreement_id
 * @property ?int $supersedes_id
 * @property bool $allow_campaigns
 * @property PriceFloorPolicy $price_floor_policy
 * @property ?CarbonImmutable $signed_at
 * @property ?string $document_sha256
 * @property ?CarbonImmutable $terminated_on
 * @property int $created_by
 */
class PriceAgreement extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'number', 'customer_id', 'title', 'currency', 'valid_from', 'valid_to', 'status',
        'crm_agreement_id', 'supersedes_id', 'allow_campaigns', 'price_floor_policy', 'signed_at',
        'document_sha256', 'terminated_on', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'SEK',
        'status' => 'DRAFT',
        'allow_campaigns' => false,
        'price_floor_policy' => 'WARN',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'status' => PriceAgreementStatus::class,
            'allow_campaigns' => 'boolean',
            'price_floor_policy' => PriceFloorPolicy::class,
            'signed_at' => 'immutable_datetime',
            'terminated_on' => 'immutable_date',
        ];
    }

    /** The number as shown: PA-12. */
    public function reference(): string
    {
        return "PA-{$this->number}";
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PriceAgreementItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceAgreementItem::class);
    }

    /** @return BelongsTo<PriceAgreement, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(PriceAgreement::class, 'supersedes_id');
    }
}
