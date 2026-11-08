<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models\Concerns;

use App\Models\Invoice;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Time and material entries are append-only (ADR-074 §3): corrected by a
 * reversing entry, never changed or removed. Only the reversal link (once)
 * and the invoice that reserves the entry may change, and a database
 * trigger enforces the same.
 *
 * @mixin Model
 */
trait AppendOnlyEntry
{
    /** Columns that may change after the entry is stored. */
    private const array MUTABLE = ['reversed_by_id', 'invoice_id', 'updated_at'];

    protected static function bootAppendOnlyEntry(): void
    {
        static::updating(function (Model $entry): void {
            // A changed company is BelongsToCompany's to refuse (MissingCompanyContext); the trigger refuses it too.
            if (array_diff(array_keys($entry->getDirty()), [...self::MUTABLE, 'company_id']) !== []) {
                throw new LogicException('A work order entry cannot be changed; reverse it instead.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A work order entry cannot be removed; reverse it instead.');
        });
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    /** Neither a reversal nor reversed: counts on its own. */
    public function isLive(): bool
    {
        return ! $this->isReversal() && ! $this->isReversed();
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<ChangeOrder, $this> */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
