<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * When a job or an operator script last ran (ADR-078). Instance data: no
 * company_id. Written by App\Support\Status\Heartbeats only.
 *
 * @property string $key
 * @property CarbonImmutable $last_run_at
 * @property ?CarbonImmutable $last_success_at
 * @property ?CarbonImmutable $last_failure_at
 */
class StatusHeartbeat extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['key', 'last_run_at', 'last_success_at', 'last_failure_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_run_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }
}
