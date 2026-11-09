<?php

declare(strict_types=1);

namespace App\Models;

use App\Migration\Import\RegisterKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded register file, list of open invoices or ZIP of attachments:
 * the detected format, the mapping, the validation report and the result
 * (ADR-076). The file is kept 30 days after a finished import, an
 * abandoned preview 7 days.
 *
 * @property int $user_id
 * @property string $kind
 * @property string $status
 * @property string $original_name
 * @property ?string $file_path
 * @property int $size
 * @property string $sha256
 * @property array<string, mixed> $options
 * @property ?array<string, mixed> $report
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $file_deleted_at
 * @property CarbonImmutable $created_at
 */
class RegisterImport extends Model
{
    use BelongsToCompany;

    public const string MAPPING = 'MAPPING';

    public const string ANALYSED = 'ANALYSED';

    public const string RUNNING = 'RUNNING';

    public const string COMPLETED = 'COMPLETED';

    public const string FAILED = 'FAILED';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'user_id', 'kind', 'status', 'original_name', 'file_path', 'size', 'sha256',
        'options', 'report', 'result', 'error', 'started_at', 'finished_at', 'file_deleted_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'MAPPING'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'options' => 'array',
            'report' => 'array',
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'file_deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerKind(): RegisterKind
    {
        return RegisterKind::from($this->kind);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::MAPPING => __('Väntar på kolumnkoppling'),
            self::ANALYSED => __('Granskad'),
            self::RUNNING => __('Läses in'),
            self::COMPLETED => __('Inläst'),
            default => __('Misslyckades'),
        };
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return array_values(array_map(strval(...), (array) ($this->report['blockers'] ?? [])));
    }

    public function canBeConfirmed(): bool
    {
        return $this->status === self::ANALYSED && $this->blockers() === [] && $this->file_path !== null;
    }
}
