<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\LabourSource;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Direct labour on a production order (ADR-071 §8): minutes on one of its
 * operations, priced at the operation's hourly cost when they were
 * recorded. Append-only; a correction is a new row with negative minutes.
 * Until the workshop terminal (M5) exists, rows come from finishing an
 * order: the minutes entered, or the planned minutes. The terminal writes
 * rows with source TERMINAL through the same table.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int $production_order_operation_id
 * @property LabourSource $source
 * @property int $minutes
 * @property int $hourly_cost_ore
 * @property int $cost_ore
 * @property CarbonImmutable $worked_on
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 */
class ProductionLabourEntry extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'production_order_id',
        'production_order_operation_id',
        'source',
        'minutes',
        'hourly_cost_ore',
        'cost_ore',
        'worked_on',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => LabourSource::class,
            'minutes' => 'integer',
            'hourly_cost_ore' => 'integer',
            'cost_ore' => 'integer',
            'worked_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A labour entry is never changed.'));
        static::deleting(fn () => throw new LogicException('A labour entry is never deleted.'));
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
