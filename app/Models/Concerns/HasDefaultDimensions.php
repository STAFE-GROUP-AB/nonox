<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CostCenter;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cost centre and project an article or customer gives a new invoice,
 * quote or order row (ADR-081 gap 6). The article's default comes before the
 * customer's; a row can always be changed.
 *
 * @property ?int $default_cost_center_id
 * @property ?int $default_project_id
 */
trait HasDefaultDimensions
{
    /** @return BelongsTo<CostCenter, $this> */
    public function defaultCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'default_cost_center_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function defaultProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'default_project_id');
    }
}
