<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CostCenter;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cost centre and project of an invoice, quote or order row (ADR-081
 * gap 6, ADR-009). The invoice voucher books the row's revenue on them.
 *
 * @property ?int $cost_center_id
 * @property ?int $project_id
 */
trait HasRowDimensions
{
    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
