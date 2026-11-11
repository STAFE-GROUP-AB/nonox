<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operation flagged on the workshop terminal as waiting for material or
 * for work (ADR-071 §7, §13.5), with a short reason. It names the device
 * that raised it, never a person. One open flag per operation; clearing it
 * keeps the row, so the analysis can total the waiting time by kind.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int $production_order_operation_id
 * @property WaitingKind $kind
 * @property string $reason
 * @property int $raised_by_device_id
 * @property CarbonImmutable $raised_at
 * @property ?CarbonImmutable $cleared_at
 * @property ?int $cleared_by_device_id
 * @property ?int $cleared_by_user_id
 */
class WorkshopWaitingFlag extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'production_order_id',
        'production_order_operation_id',
        'kind',
        'reason',
        'raised_by_device_id',
        'raised_at',
        'cleared_at',
        'cleared_by_device_id',
        'cleared_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => WaitingKind::class,
            'raised_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<ProductionOrderOperation, $this> */
    public function orderOperation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }
}
