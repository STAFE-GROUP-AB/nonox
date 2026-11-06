<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An arbetsgivardeklaration file read into the company's own space at
 * Skatteverket through its API (ADR-054, stage 2). Reading it in does not
 * file the declaration: an authorised person reviews and submits it in
 * Skatteverket's e-service Arbetsgivardeklaration on Mina sidor.
 *
 * @property int $id
 * @property int $company_id
 * @property int $agi_submission_id
 * @property string $period
 * @property int $upload_id
 * @property string $sha256
 * @property string $status
 * @property ?string $report_status
 * @property ?int $main_count
 * @property ?int $individual_count
 * @property ?int $error_count
 * @property ?int $warning_count
 * @property ?string $review_state
 * @property ?string $review_link
 * @property ?CarbonImmutable $review_created_at
 * @property ?string $period_state
 * @property ?bool $period_signed
 * @property ?int $receipt_count
 * @property ?string $latest_receipt_id
 * @property ?CarbonImmutable $latest_signed_at
 * @property ?CarbonImmutable $period_fetched_at
 * @property ?int $uploaded_by
 * @property CarbonImmutable $uploaded_at
 * @property ?CarbonImmutable $checked_at
 */
class AgiUpload extends Model
{
    use BelongsToCompany;

    /** Skatteverket is still checking the file. */
    public const string PROCESSING = 'PROCESSING';

    /** Checked without errors and saved in the own space. */
    public const string SAVED = 'SAVED';

    /** Checked with errors: not saved yet; save anyway or cancel within 24 hours. */
    public const string FAILED = 'FAILED';

    /** Errors in the file itself: Skatteverket saved nothing. */
    public const string REJECTED = 'REJECTED';

    /** Saved although the check found errors; they must be put right on Mina sidor. */
    public const string SAVED_WITH_ERRORS = 'SAVED_WITH_ERRORS';

    /** Removed from the own space (cancelled, removed or replaced by a newer file). */
    public const string REMOVED = 'REMOVED';

    /** Statuses whose data lies in the own space at Skatteverket. */
    public const array IN_OWN_SPACE = [self::SAVED, self::SAVED_WITH_ERRORS];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'agi_submission_id',
        'period',
        'upload_id',
        'sha256',
        'status',
        'uploaded_by',
        'uploaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'upload_id' => 'integer',
            'main_count' => 'integer',
            'individual_count' => 'integer',
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'review_created_at' => 'immutable_datetime',
            'period_signed' => 'boolean',
            'receipt_count' => 'integer',
            'latest_signed_at' => 'immutable_datetime',
            'period_fetched_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PROCESSING => __('Skatteverket kontrollerar filen'),
            self::SAVED => __('Sparad i eget utrymme hos Skatteverket'),
            self::FAILED => __('Fel i kontrollen, inte sparad'),
            self::REJECTED => __('Avvisad av Skatteverket, inte sparad'),
            self::SAVED_WITH_ERRORS => __('Sparad med fel i eget utrymme hos Skatteverket'),
            default => __('Borttagen hos Skatteverket'),
        };
    }

    public function isInOwnSpace(): bool
    {
        return in_array($this->status, self::IN_OWN_SPACE, true);
    }

    /** @return BelongsTo<AgiSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AgiSubmission::class, 'agi_submission_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
