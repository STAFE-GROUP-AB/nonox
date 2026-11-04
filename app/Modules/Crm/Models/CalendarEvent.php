<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which event in a connected calendar is which CRM activity (ADR-049). Only
 * events listed here are ever read back from a calendar: nothing else in the
 * user's calendar is imported.
 *
 * @property int $id
 * @property int $company_id
 * @property int $connection_id
 * @property int $activity_id
 * @property string $external_id
 * @property int $synced_sequence
 * @property ?CarbonImmutable $synced_at
 */
class CalendarEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_calendar_events';

    /** @var list<string> */
    protected $fillable = ['company_id', 'connection_id', 'activity_id', 'external_id', 'synced_sequence', 'synced_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'activity_id' => 'integer',
            'synced_sequence' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<CalendarConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'connection_id');
    }
}
