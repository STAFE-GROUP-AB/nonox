<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceAgreementActivation;
use App\Domain\Pricing\PriceAgreementActivator;
use App\Domain\Pricing\PriceAgreementPdf;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningHooks;
use App\Modules\Crm\Models\Agreement;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * A customer's negotiated prices, "Prisavtal" in the interface (ADR-082 §4),
 * numbered PA-1, PA-2 … per company.
 *
 * Life cycle (P5): a DRAFT is edited freely; sending it for signing makes it
 * AWAITING_SIGNATURE, and the signature (or an activation by hand with a
 * reason) makes it ACTIVE. Once it has left DRAFT its terms never change: a
 * change is a new version that supersedes it ("Ändra avtal"). Only the
 * life-cycle fields below may still be written, and only a draft may be
 * deleted (model event here, policy, and a database trigger).
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
 * @property ?PriceAgreementActivation $activation_method
 * @property ?CarbonImmutable $activated_at
 * @property ?int $activated_by
 * @property ?string $activation_reason
 * @property int $created_by
 * @property ?CarbonImmutable $created_at
 */
class PriceAgreement extends Model implements Signable
{
    use BelongsToCompany;

    /** The type on signing requests (at most ten characters, SignableTypes). */
    public const string SIGNING_TYPE = 'PRICE';

    /** What may still be written once the agreement has left DRAFT: its life cycle, never its terms. */
    private const array LIFE_CYCLE = [
        'status', 'valid_to', 'terminated_on', 'signed_at', 'document_sha256',
        'activation_method', 'activated_at', 'activated_by', 'activation_reason', 'updated_at',
    ];

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

    protected static function booted(): void
    {
        static::updating(function (self $agreement): void {
            if (PriceAgreementStatus::from((string) $agreement->getRawOriginal('status'))->isDraft()) {
                return;
            }

            if (array_diff(array_keys($agreement->getDirty()), self::LIFE_CYCLE) !== []) {
                throw new LogicException('A price agreement that has left draft cannot change its terms; make a new version.');
            }
        });

        static::deleting(function (self $agreement): void {
            if (! PriceAgreementStatus::from((string) $agreement->getRawOriginal('status'))->isDraft()) {
                throw new LogicException('Only a draft price agreement can be deleted.');
            }
        });
    }

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
            'activation_method' => PriceAgreementActivation::class,
            'activated_at' => 'immutable_datetime',
        ];
    }

    /** The number as shown: PA-12. */
    public function reference(): string
    {
        return "PA-{$this->number}";
    }

    /** The last day the agreement prices documents: its end, or the day it was terminated to, whichever is earlier. */
    public function lastDay(): ?CarbonImmutable
    {
        if ($this->terminated_on === null) {
            return $this->valid_to;
        }

        return $this->valid_to === null || $this->terminated_on->lessThan($this->valid_to) ? $this->terminated_on : $this->valid_to;
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

    /** @return BelongsTo<Agreement, $this> */
    public function crmAgreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'crm_agreement_id');
    }

    /*
     * Signing at a link through the shared signing domain (ADR-046,
     * ADR-075 §7). Core, like the quote: no module has to be on. The
     * document is the agreement's PDF; its terms are fixed from sending,
     * and the signature activates it (PriceAgreementActivator).
     */

    public function signingType(): string
    {
        return self::SIGNING_TYPE;
    }

    public function signingKey(): string
    {
        return (string) $this->id;
    }

    public function signingCompanyId(): int
    {
        return $this->company_id;
    }

    public function signingTitle(): string
    {
        return __('Prisavtal :reference: :title', ['reference' => $this->reference(), 'title' => $this->title]);
    }

    public function signingDocument(): array
    {
        return ['bytes' => PriceAgreementPdf::render($this), 'name' => PriceAgreementPdf::filename($this)];
    }

    public function signingFingerprint(): string
    {
        return hash('sha256', PriceAgreementPdf::html($this));
    }

    public function signingIsOpen(): bool
    {
        return $this->status === PriceAgreementStatus::AwaitingSignature;
    }

    /** For a company, also that the signer may enter it on its behalf; a private person keeps the short form. */
    public function signingAcceptance(): string
    {
        $customer = Customer::query()->find($this->customer_id);
        $party = $customer === null ? null : app(SigningHooks::class)->party($customer);
        $legalPerson = $party !== null
            ? $party['legal_person']
            : $customer !== null && $customer->org_number !== null && $customer->personal_number === null;

        return $legalPerson
            ? __('Jag har läst och godkänner prisavtalet och har rätt att ingå det för :customer.', ['customer' => $party['name'] ?? $customer?->name])
            : __('Jag har läst och godkänner prisavtalet.');
    }

    public function signingModule(): ?string
    {
        return null;
    }

    public function signingValidUntil(): ?CarbonImmutable
    {
        return null;
    }

    public function signingDestination(): string
    {
        return 'price-agreements';
    }

    public function authorizeSigning(User $user, bool $revoke = false): void
    {
        Gate::forUser($user)->authorize('update', $this);
    }

    /** Signed at the link: the agreement becomes active (and supersedes the version it changes). */
    public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void
    {
        app(PriceAgreementActivator::class)->activate($this, PriceAgreementActivation::Signed, $signedAt, $documentSha256);
    }
}
