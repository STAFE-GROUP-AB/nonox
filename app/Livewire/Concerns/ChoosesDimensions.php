<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\CostCenter;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * The cost centres and projects a screen offers (ADR-081 gap 6): the active
 * ones, in code order; two queries. A screen shows the choices only when
 * the company has any, so a company without dimensions sees nothing new
 * (as the voucher editor, ADR-009).
 */
trait ChoosesDimensions
{
    /**
     * @return array{costCenters: Collection<int, CostCenter>, projects: Collection<int, Project>}
     */
    protected function dimensionChoices(): array
    {
        return [
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'projects' => Project::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'default_cost_center_id']),
        ];
    }
}
