<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inköpsanmodan (ADR-081 gap 3, E9): a request for goods or services that is
 * approved through the approval engine and then turned into purchase order
 * rows by a buyer. A request, not a commitment; it creates no voucher.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property PurchaseRequisitionStatus $status
 * @property int $requested_by
 * @property ?int $prepared_by
 * @property ?CarbonImmutable $needed_by
 * @property ?string $reason
 * @property int $approval_round
 * @property ?int $approval_request_id
 * @property ?CarbonInterface $submitted_at
 * @property ?int $approved_by
 * @property ?CarbonInterface $approved_at
 * @property ?int $rejected_by
 * @property ?CarbonInterface $rejected_at
 * @property ?string $reject_reason
 * @property ?CarbonInterface $cancelled_at
 */
class PurchaseRequisition extends Model
{
    use BelongsToCompany;

    public const string SERIES = 'purchase_requisitions';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'status',
        'requested_by',
        'prepared_by',
        'needed_by',
        'reason',
        'approval_round',
        'approval_request_id',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'cancelled_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'approval_round' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => PurchaseRequisitionStatus::class,
            'needed_by' => 'immutable_date',
            'approval_round' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function title(): string
    {
        return __('Inköpsanmodan :number', ['number' => $this->number]);
    }

    /** What the requester thinks it costs, excluding VAT: rows with a price only. Reads the loaded rows. */
    public function estimatedOre(): int
    {
        return (int) $this->rows->sum(fn (PurchaseRequisitionRow $row): int => $row->estimatedValueOre() ?? 0);
    }

    /** Whether some row has no price, so the estimate is lower than the whole. */
    public function hasUnpricedRows(): bool
    {
        return $this->rows->contains(fn (PurchaseRequisitionRow $row): bool => $row->estimated_price_ore === null);
    }

    /** @return HasMany<PurchaseRequisitionRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionRow::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
