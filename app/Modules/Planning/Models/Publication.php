<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * That a period of the schedule was published: by whom, when, how many
 * shifts and how many people were told.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $from
 * @property CarbonImmutable $to
 * @property ?int $published_by
 * @property CarbonImmutable $published_at
 * @property int $shift_count
 * @property int $notified_count
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Publication extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_publications';

    /** @var list<string> */
    protected $fillable = ['company_id', 'from', 'to', 'published_by', 'published_at', 'shift_count', 'notified_count'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from' => 'immutable_date',
            'to' => 'immutable_date',
            'published_at' => 'immutable_datetime',
            'shift_count' => 'integer',
            'notified_count' => 'integer',
        ];
    }
}
