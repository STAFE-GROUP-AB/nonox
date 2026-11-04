<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of registry:import (ADR-047): its source, the file version, how
 * far it got (to resume) and its result. Instance data.
 *
 * @property int $id
 * @property string $source
 * @property ?string $version
 * @property string $status RUNNING, DONE or FAILED
 * @property int $rows_read
 * @property int $rows_written
 * @property int $rows_skipped
 * @property int $changes
 * @property ?string $position
 * @property ?string $error
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 */
class RegistryImport extends Model
{
    public const string RUNNING = 'RUNNING';

    public const string DONE = 'DONE';

    public const string FAILED = 'FAILED';

    protected $table = 'company_registry_imports';

    /** @var list<string> */
    protected $fillable = ['source', 'version', 'status', 'rows_read', 'rows_written', 'rows_skipped', 'changes', 'position', 'error', 'started_at', 'finished_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_written' => 'integer',
            'rows_skipped' => 'integer',
            'changes' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
