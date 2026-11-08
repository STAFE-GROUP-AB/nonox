<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Modules\WorkOrders\Domain\ChangeOrderBasis;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\ContactChannel;
use App\Modules\WorkOrders\Domain\PerformedReason;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class ChangeOrder extends Model
{
    use BelongsToCompany;

    /** Content that cannot change once the ÄTA is sent. */
    private const array MUTABLE_WHEN_SENT = ['status', 'decided_at', 'approval_method', 'approved_by_name', 'approved_by_title', 'decided_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'work_order_id', 'title', 'description', 'basis', 'price_form', 'labour_ore', 'material_ore', 'other_ore',
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
            $was = ChangeOrderStatus::from((string) $order->getOriginal('status'));

            if ($was->isDecided()) {
                throw new LogicException('A decided change order cannot be changed.');
            }

            if ($was === ChangeOrderStatus::Sent && array_diff(array_keys($order->getDirty()), self::MUTABLE_WHEN_SENT) !== []) {
                throw new LogicException('A sent change order cannot be changed.');
            }
        });

        static::deleting(function (self $order): void {
            if (ChangeOrderStatus::from((string) $order->getOriginal('status')) !== ChangeOrderStatus::Draft) {
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
