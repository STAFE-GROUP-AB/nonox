<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff-cost budget for one ISO week and an area or the whole company
 * (area null), ADR-037 gap 2: a fixed amount, or a share of the week's
 * forecast sales (gap 1) in basis points. Exactly one of the two is set.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $area_id
 * @property CarbonImmutable $week_start
 * @property ?int $amount_ore
 * @property ?int $percent_bp
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Budget extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_budgets';

    /** @var list<string> */
    protected $fillable = ['company_id', 'area_id', 'week_start', 'amount_ore', 'percent_bp', 'created_by', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'week_start' => 'immutable_date',
            'amount_ore' => 'integer',
            'percent_bp' => 'integer',
        ];
    }

    public function isPercent(): bool
    {
        return $this->percent_bp !== null;
    }
}
