<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Models\User;
use App\Modules\Boardroom\Domain\ShareEventKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in the share register (ADR-070 §2). Append-only: the model
 * refuses changes and deletes, a PostgreSQL trigger backs it up, and every
 * entry carries hash = sha256(previous_hash || canonical_json(entry)) in a
 * chain per company (EventHash). The recipient's details are copied into
 * holder_snapshot (encrypted), so the register shows what was true at the
 * time even after an address change, and data removed from the register
 * stays for the ten years ABL 5 kap. 3 § requires.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $sequence
 * @property ShareEventKind $kind
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable $recorded_at
 * @property ?CarbonImmutable $decided_on
 * @property ?CarbonImmutable $registered_on
 * @property ?int $share_from
 * @property ?int $share_to
 * @property ?int $share_class_id
 * @property ?int $from_holder_id
 * @property ?int $to_holder_id
 * @property ?array<string, mixed> $holder_snapshot
 * @property ?int $consideration_ore
 * @property ?int $capital_change_ore
 * @property ?bool $certificate_issued
 * @property ?int $ratio
 * @property ?int $refers_to_event_id
 * @property ?string $note
 * @property ?string $document_reference
 * @property ?string $reason
 * @property ?int $user_id
 * @property ?string $previous_hash
 * @property string $hash
 * @property ?CarbonImmutable $created_at
 */
class ShareEvent extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'boardroom_share_events';

    /** @var list<string> */
    protected $hidden = ['holder_snapshot'];

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'company_id',
        'sequence',
        'kind',
        'effective_date',
        'recorded_at',
        'decided_on',
        'registered_on',
        'share_from',
        'share_to',
        'share_class_id',
        'from_holder_id',
        'to_holder_id',
        'holder_snapshot',
        'consideration_ore',
        'capital_change_ore',
        'certificate_issued',
        'ratio',
        'refers_to_event_id',
        'note',
        'document_reference',
        'reason',
        'user_id',
        'previous_hash',
        'hash',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ShareEventKind::class,
            'sequence' => 'integer',
            'effective_date' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'decided_on' => 'immutable_date',
            'registered_on' => 'immutable_date',
            'share_from' => 'integer',
            'share_to' => 'integer',
            'holder_snapshot' => 'encrypted:array',
            'consideration_ore' => 'integer',
            'capital_change_ore' => 'integer',
            'certificate_issued' => 'boolean',
            'ratio' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Share register entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Share register entries cannot be deleted.');
        });
    }

    /** Number of shares in the range, or zero. */
    public function shareCount(): int
    {
        return $this->share_from === null || $this->share_to === null ? 0 : $this->share_to - $this->share_from + 1;
    }

    /** "1–500", or an empty string. */
    public function rangeLabel(): string
    {
        return $this->share_from === null ? '' : "{$this->share_from}–{$this->share_to}";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Shareholder, $this> */
    public function fromHolder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'from_holder_id');
    }

    /** @return BelongsTo<Shareholder, $this> */
    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'to_holder_id');
    }
}
