<?php

declare(strict_types=1);

namespace App\Modules\Signing\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk send for signing (ADR-046 amendment 2026-11-18, gap 8, package S15):
 * one template and a file with one row per envelope. PREVIEW while the rows
 * are only checked (nothing is sent), RUNNING once a person has confirmed it
 * and the envelopes are made in the background, then COMPLETED or CANCELLED.
 *
 * @property string $id
 * @property int $company_id
 * @property ?string $template_id
 * @property string $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $sent_count
 * @property int $failed_count
 * @property ?int $created_by
 * @property ?CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $created_at
 */
class SigningBulkSend extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string PREVIEW = 'PREVIEW';

    public const string RUNNING = 'RUNNING';

    public const string COMPLETED = 'COMPLETED';

    public const string CANCELLED = 'CANCELLED';

    /** At most this many envelopes in one run (ADR-046 owner decision 15). */
    public const int MAX_ROWS = 500;

    /** Times are written with their offset, like the rest of signing. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['company_id', 'template_id', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'sent_count', 'failed_count', 'created_by', 'confirmed_at', 'started_at', 'finished_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PREVIEW, self::RUNNING], true);
    }

    /** @return BelongsTo<SigningTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SigningTemplate::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SigningBulkSendRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(SigningBulkSendRow::class, 'bulk_send_id')->orderBy('row_number');
    }
}
