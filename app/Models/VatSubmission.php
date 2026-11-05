<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Vat\VatSubmissionStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A VAT return sent to Skatteverket as a draft (ADR-054). Sending it does not
 * file it: the return is filed when an authorised person signs it at
 * Skatteverket through the signing link.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property VatSubmissionStatus $status
 * @property array<int, int> $boxes
 * @property ?string $check_status
 * @property ?list<array{status: string, kod: string, beskrivning: string}> $check_results
 * @property bool $locked
 * @property ?string $signing_link
 * @property ?string $receipt_number
 * @property ?CarbonImmutable $submitted_at
 * @property ?array<int, int> $decided_boxes
 * @property ?CarbonImmutable $decided_at
 * @property ?int $sent_by
 * @property ?CarbonImmutable $sent_at
 */
class VatSubmission extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'status',
        'boxes',
        'check_status',
        'check_results',
        'locked',
        'signing_link',
        'receipt_number',
        'submitted_at',
        'decided_boxes',
        'decided_at',
        'sent_by',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => VatSubmissionStatus::class,
            'boxes' => 'array',
            'check_results' => 'array',
            'locked' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'decided_boxes' => 'array',
            'decided_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
