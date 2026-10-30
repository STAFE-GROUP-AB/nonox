<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An archive package for one fiscal year (AGENTS.md §10): a ZIP with the
 * SIE file, reports, vouchers with their underlag, invoices, VAT returns,
 * payroll documents, the behandlingshistorik and the systemdokumentation,
 * and a manifest with the SHA-256 of every file.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $status
 * @property ?string $file_path
 * @property ?string $file_name
 * @property ?int $size
 * @property ?string $sha256
 * @property ?int $file_count
 * @property ?array<string, int> $contents
 * @property ?string $error
 * @property int $created_by
 * @property ?CarbonImmutable $completed_at
 * @property CarbonImmutable $created_at
 */
class ArchivePackage extends Model
{
    use BelongsToCompany;

    public const string QUEUED = 'QUEUED';

    public const string RUNNING = 'RUNNING';

    public const string DONE = 'DONE';

    public const string FAILED = 'FAILED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'status',
        'file_path',
        'file_name',
        'size',
        'sha256',
        'file_count',
        'contents',
        'error',
        'created_by',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contents' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
