<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\DowntimeReason;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workstation out of use from a start to an expected end (none: until
 * further notice), entered by a planner or reported from the workshop
 * terminal (ADR-071 §13.2, §13.5).
 *
 * @property int $id
 * @property int $company_id
 * @property int $workstation_id
 * @property CarbonImmutable $starts_at
 * @property ?CarbonImmutable $ends_at
 * @property DowntimeReason $reason
 * @property string $source
 * @property ?int $created_by
 */
class Downtime extends Model
{
    use BelongsToCompany;

    public const string MANUAL = 'MANUAL';

    public const string TERMINAL = 'TERMINAL';

    protected $table = 'production_downtimes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'workstation_id', 'starts_at', 'ends_at', 'reason', 'source', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reason' => DowntimeReason::class,
        ];
    }

    /** @return BelongsTo<Workstation, $this> */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
