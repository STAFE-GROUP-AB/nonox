<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Status\IncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note in an incident's history (ADR-078). Instance data: no company_id.
 *
 * @property int $id
 * @property int $status_incident_id
 * @property IncidentStatus $status
 * @property string $body
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class StatusIncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['status_incident_id', 'status', 'body', 'created_by', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StatusIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'status_incident_id');
    }
}
