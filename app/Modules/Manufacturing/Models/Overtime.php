<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Extra working time a planner gave a person (ADR-071 §13.5, a suggestion
 * or a scenario applied), on top of the working week or the shifts. Removed
 * nightly 90 days after it ended.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property ?int $created_by
 */
class Overtime extends Model
{
    use BelongsToCompany;

    /** Overtime is only input to the plan: removed this many days after it ended. */
    public const int KEEP_DAYS = 90;

    protected $table = 'production_overtimes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'employee_id', 'starts_at', 'ends_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
