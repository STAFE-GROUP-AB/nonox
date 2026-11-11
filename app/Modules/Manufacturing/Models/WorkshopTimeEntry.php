<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Who reported what on the workshop terminal (ADR-071 §7): person, order,
 * operation, day, minutes, device and the time it was entered. Append-only:
 * a correction is a new row with negative minutes that points back at the
 * row it cancels. The cost sits in the labour entry it points to
 * (production_labour_entries, source TERMINAL), which carries no person.
 * After the retention period the person is removed from the row
 * (PruneWorkshopTime); nothing else about it ever changes.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $person_id
 * @property int $device_id
 * @property int $production_order_id
 * @property int $production_order_operation_id
 * @property int $production_labour_entry_id
 * @property int $minutes
 * @property CarbonImmutable $worked_on
 * @property ?int $corrects_entry_id
 * @property CarbonImmutable $entered_at
 */
class WorkshopTimeEntry extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'person_id',
        'device_id',
        'production_order_id',
        'production_order_operation_id',
        'production_labour_entry_id',
        'minutes',
        'worked_on',
        'corrects_entry_id',
        'entered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'worked_on' => 'immutable_date',
            'entered_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            // Only the retention step may touch a row, and only to remove the person.
            if ($entry->person_id !== null || array_keys($entry->getDirty()) !== ['person_id']) {
                throw new LogicException('A terminal time entry is never changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('A terminal time entry is never deleted.'));
    }

    /** @return BelongsTo<WorkshopPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(WorkshopPerson::class, 'person_id');
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
