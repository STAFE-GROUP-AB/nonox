<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Domain\EmailDirection;
use App\Modules\Crm\Domain\EmailStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An e-mail someone at the company sent or forwarded to the company's +crm
 * address (ADR-048). The full plain-text body and the addresses are
 * encrypted at rest; the timeline shows the subject and a short excerpt
 * through the activities that point here. Attachments are never kept, only
 * their names.
 *
 * @property int $id
 * @property int $company_id
 * @property EmailDirection $direction
 * @property EmailStatus $status
 * @property string $channel
 * @property string $subject
 * @property ?string $excerpt
 * @property ?string $body
 * @property array{from: string, from_name: ?string, to: list<string>, cc: list<string>, other: list<string>, held_for?: list<int>} $participants
 * @property ?list<string> $attachment_names
 * @property ?string $message_id
 * @property string $sha256
 * @property ?int $forwarded_by
 * @property ?int $person_id the person it was matched to first; the e-mail is erased with them
 * @property CarbonImmutable $sent_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Email extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_emails';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'direction',
        'status',
        'channel',
        'subject',
        'excerpt',
        'body',
        'participants',
        'attachment_names',
        'message_id',
        'sha256',
        'forwarded_by',
        'person_id',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => EmailDirection::class,
            'status' => EmailStatus::class,
            'body' => 'encrypted',
            'participants' => 'encrypted:array',
            'attachment_names' => 'encrypted:array',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return list<string> the other party's addresses */
    public function otherParty(): array
    {
        return $this->participants['other'] ?? [];
    }
}
