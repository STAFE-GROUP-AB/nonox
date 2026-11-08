<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\WorkOrders\Domain\ChangeOrderBasis;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\ContactChannel;
use App\Modules\WorkOrders\Domain\PerformedReason;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * An ÄTA, ändrings- och tilläggsarbete (ADR-074 §4, rule sheet §D): one
 * change with its scope, basis, price and the customer's answer. Numbered
 * per work order. Once decided it never changes (AO-R25), enforced here and
 * by a database trigger; while sent, its content cannot change.
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property int $number
 * @property string $title
 * @property string $description
 * @property ChangeOrderBasis $basis
 * @property PriceForm $price_form
 * @property Money $labour_ore excluding VAT
 * @property Money $material_ore excluding VAT
 * @property Money $other_ore travel, machines and other, excluding VAT
 * @property ?Money $limit_ore the upper limit of an approximate price, including VAT
 * @property ?string $time_effect
 * @property ?string $payment_terms
 * @property bool $tax_reduction_applies
 * @property ChangeOrderStatus $status
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $expires_on
 * @property ?CarbonImmutable $decided_at
 * @property ?string $approval_method LINK, ON_SITE, ORAL or PORTAL
 * @property ?string $approved_by_name
 * @property ?string $approved_by_title
 * @property ?ContactChannel $oral_channel
 * @property ?PerformedReason $performed_reason
 * @property ?string $justification
 * @property ?string $document_sha256
 * @property ?int $decided_by
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 */
class ChangeOrder extends Model implements Signable
{
    use BelongsToCompany;

    /** The type on signing requests (at most ten characters, SignableTypes). */
    public const string SIGNING_TYPE = 'ATA';

    /** Content that cannot change once the ÄTA is sent. */
    private const array MUTABLE_WHEN_SENT = ['status', 'decided_at', 'approval_method', 'approved_by_name', 'approved_by_title', 'decided_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'work_order_id', 'number', 'title', 'description', 'basis', 'price_form', 'labour_ore', 'material_ore', 'other_ore',
        'limit_ore', 'time_effect', 'payment_terms', 'tax_reduction_applies', 'expires_on', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'labour_ore' => 0,
        'material_ore' => 0,
        'other_ore' => 0,
        'tax_reduction_applies' => true,
    ];

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $was = ChangeOrderStatus::from((string) $order->getRawOriginal('status'));

            if ($was->isDecided()) {
                throw new LogicException('A decided change order cannot be changed.');
            }

            if ($was === ChangeOrderStatus::Sent && array_diff(array_keys($order->getDirty()), self::MUTABLE_WHEN_SENT) !== []) {
                throw new LogicException('A sent change order cannot be changed.');
            }
        });

        static::deleting(function (self $order): void {
            if (ChangeOrderStatus::from((string) $order->getRawOriginal('status')) !== ChangeOrderStatus::Draft) {
                throw new LogicException('Only a draft change order can be removed.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'basis' => ChangeOrderBasis::class,
            'price_form' => PriceForm::class,
            'labour_ore' => MoneyCast::class,
            'material_ore' => MoneyCast::class,
            'other_ore' => MoneyCast::class,
            'limit_ore' => MoneyCast::class,
            'tax_reduction_applies' => 'boolean',
            'status' => ChangeOrderStatus::class,
            'sent_at' => 'immutable_datetime',
            'expires_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
            'oral_channel' => ContactChannel::class,
            'performed_reason' => PerformedReason::class,
        ];
    }

    public function label(): string
    {
        return __('ÄTA :number', ['number' => $this->number]);
    }

    /** Labour, material and other, excluding VAT. */
    public function netOre(): int
    {
        return $this->labour_ore->ore + $this->material_ore->ore + $this->other_ore->ore;
    }

    /*
     * Signing at a link through the shared signing domain (ADR-046,
     * ADR-075 §7, ADR-074 §4). The document is the ÄTA's PDF; its content
     * is fixed while it is sent (trigger), and once signed the ÄTA is
     * APPROVED and never changes again.
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
        return "{$this->label()}: {$this->title}";
    }

    public function signingDocument(): array
    {
        return ['bytes' => ChangeOrderPdf::render($this), 'name' => ChangeOrderPdf::filename($this)];
    }

    /** The document part of the ÄTA (without the decision), as the change order trigger keeps it while it is sent. */
    public function signingFingerprint(): string
    {
        return hash('sha256', ChangeOrderPdf::html($this));
    }

    public function signingIsOpen(): bool
    {
        return $this->status === ChangeOrderStatus::Sent;
    }

    /** For a business customer, also that the signer may approve on its behalf; a private person keeps the short form. */
    public function signingAcceptance(): string
    {
        $customer = Customer::query()->find((int) WorkOrder::query()->whereKey($this->work_order_id)->value('customer_id'));

        return $customer instanceof Customer && filled($customer->org_number) && blank($customer->personal_number)
            ? __('Jag har läst och godkänner ändrings- eller tilläggsarbetet och priset och har rätt att godkänna det för :customer.', ['customer' => $customer->name])
            : __('Jag har läst och godkänner ändrings- eller tilläggsarbetet och priset.');
    }

    public function signingModule(): ?string
    {
        return WorkOrdersModule::KEY;
    }

    public function signingValidUntil(): ?CarbonImmutable
    {
        return $this->expires_on;
    }

    public function signingDestination(): string
    {
        return 'work-orders';
    }

    /**
     * Sending needs change-orders:send. A field worker assigned to the work
     * order may present an ÄTA the office has already sent on site
     * (ADR-074 O4); the customer then confirms with a code to their own
     * e-mail before the page opens.
     */
    public function authorizeSigning(User $user, bool $revoke = false): void
    {
        $company = Company::query()->findOrFail($this->company_id);

        if ($user->hasTeamPermission($company, WorkOrdersModule::SEND)) {
            return;
        }

        $workOrder = WorkOrder::query()->findOrFail($this->work_order_id);
        abort_unless(! $revoke && $this->status === ChangeOrderStatus::Sent && Gate::forUser($user)->allows('report', $workOrder), 403);
    }

    /**
     * Signed at the link: APPROVED, with who signed (the signing request)
     * and whether the page was presented on site after a code (O4).
     */
    public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void
    {
        $request = SigningRequest::query()->where('document_type', self::SIGNING_TYPE)->where('signable_id', (string) $this->id)
            ->where('status', SigningStatus::Signed->value)->latest('signed_at')->first();
        $onSite = $request instanceof SigningRequest && OnSiteCode::query()->where('signing_request_id', $request->id)->whereNotNull('verified_at')->exists();

        $this->forceFill([
            'status' => ChangeOrderStatus::Approved,
            'decided_at' => $signedAt,
            'approval_method' => $onSite ? 'ON_SITE' : 'LINK',
            'approved_by_name' => $request?->signer_name,
            'approved_by_title' => $request?->signer_title,
        ])->save();
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return HasMany<ContactAttempt, $this> */
    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class)->orderBy('attempted_at');
    }
}
