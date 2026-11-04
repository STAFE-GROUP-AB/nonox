<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Domain\MailingStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mailing (utskick) to a segment (ADR-048): Markdown rendered with the
 * company's look, sent in batches through the queue, with clicks counted
 * per link through redirect links that name no recipient, and never opens.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $segment_id
 * @property string $name
 * @property string $subject
 * @property string $body
 * @property bool $customer_exception
 * @property MailingStatus $status
 * @property ?CarbonImmutable $scheduled_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?int $created_by
 * @property ?int $sent_by
 * @property int $recipient_count
 * @property int $sent_count
 * @property int $failed_count
 * @property int $bounced_count
 * @property int $delivered_count
 * @property int $clicks clicks on the mailing's links; never per recipient
 * @property int $complaint_count spam complaints reported by the mailbox providers
 * @property int $unsubscribed_count
 * @property ?array<string, int> $excluded
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Mailing extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_mailings';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'segment_id',
        'name',
        'subject',
        'body',
        'customer_exception',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'created_by',
        'sent_by',
        'recipient_count',
        'excluded',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'customer_exception' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MailingStatus::class,
            'customer_exception' => 'boolean',
            'excluded' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /** @return HasMany<MailingLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(MailingLink::class)->orderBy('position');
    }

    /** @return HasMany<MailingRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MailingRecipient::class);
    }
}
