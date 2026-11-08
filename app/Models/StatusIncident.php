<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Status\IncidentSeverity;
use App\Domain\Status\IncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An incident or planned maintenance on the public status page (ADR-078).
 * Instance data: no company_id. The text is public, written by an HQ admin.
 *
 * @property int $id
 * @property string $title
 * @property IncidentSeverity $severity
 * @property IncidentStatus $status
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $resolved_at
 * @property ?int $created_by
 */
class StatusIncident extends Model
{
    public const int TITLE_MAX = 160;

    public const int BODY_MAX = 2000;

    /** @var list<string> */
    protected $fillable = ['title', 'severity', 'status', 'started_at', 'resolved_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'started_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StatusIncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusIncidentUpdate::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
