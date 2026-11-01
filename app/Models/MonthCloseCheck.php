<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\MonthCloseStep;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A step of a client's month checked off by hand on Månadsstängning.
 *
 * @property int $id
 * @property int $company_id
 * @property string $month
 * @property MonthCloseStep $step
 * @property int $checked_by
 * @property CarbonImmutable $checked_at
 */
class MonthCloseCheck extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'month', 'step', 'checked_by', 'checked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => MonthCloseStep::class,
            'checked_at' => 'immutable_datetime',
        ];
    }
}
