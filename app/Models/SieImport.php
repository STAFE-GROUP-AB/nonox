<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded SIE file: its validation report, and once it has been read
 * in, the reconciliation against the file (AGENTS.md §9.2).
 *
 * @property int $user_id
 * @property SieImportStatus $status
 * @property string $original_name
 * @property string $file_path
 * @property int $size
 * @property string $sha256
 * @property ?int $fiscal_year_id
 * @property array<string, mixed> $report
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property CarbonImmutable $created_at
 */
class SieImport extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'original_name',
        'file_path',
        'size',
        'sha256',
        'fiscal_year_id',
        'report',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'ANALYSED',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SieImportStatus::class,
            'size' => 'integer',
            'report' => 'array',
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Problems in the report that stop the file from being read in.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        return array_values(array_map(strval(...), (array) ($this->report['blockers'] ?? [])));
    }

    public function canBeConfirmed(): bool
    {
        return $this->status === SieImportStatus::Analysed && $this->blockers() === [];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
