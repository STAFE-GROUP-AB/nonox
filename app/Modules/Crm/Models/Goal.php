<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Domain\GoalMetric;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's goal for one month (ADR-043 stage 2): a number of meetings or
 * quotes, or an order value in öre. Progress is measured from the data
 * (GoalProgress), never typed in.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CarbonImmutable $month the first day of the month
 * @property GoalMetric $metric
 * @property int $target
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Goal extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_goals';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'month', 'metric', 'target'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'metric' => GoalMetric::class,
            'target' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
