<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Models\User;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A set of minutes: a draft, then justerat (checked by the chair and the
 * adjusters, ABL 7 kap. 48 §, 8 kap. 24 §), then "Godkänt i NONOX" with who
 * and when. Approval freezes the body, writes a PDF and keeps its SHA-256;
 * from then the row is locked here and by a PostgreSQL trigger. A mistake is
 * corrected with a new set of minutes that names the one it corrects. Board
 * minutes get a number in order when approved (8 kap. 26 §).
 *
 * @property int $id
 * @property int $company_id
 * @property int $meeting_id
 * @property ?int $number
 * @property MinutesStatus $status
 * @property array{items: list<array{position: int, title: string, proposal?: ?string, decision: ?string, dissent?: ?string}>, notes?: ?string, attendees?: list<array{name: string, role: string}>, chair?: ?string, minute_taker?: ?string, adjusters?: list<string>, meeting?: array<string, mixed>} $body
 * @property ?list<string> $checked_names
 * @property ?int $checked_by
 * @property ?CarbonImmutable $checked_at
 * @property ?int $approved_by
 * @property ?CarbonImmutable $approved_at
 * @property ?string $pdf_path
 * @property ?string $sha256
 * @property ?int $corrects_minutes_id
 * @property ?int $created_by
 */
class Minutes extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_minutes';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'meeting_id', 'number', 'status', 'body', 'checked_names', 'checked_by', 'checked_at',
        'approved_by', 'approved_at', 'pdf_path', 'sha256', 'corrects_minutes_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => MinutesStatus::class,
            'body' => 'array',
            'checked_names' => 'array',
            'checked_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $minutes): void {
            if ($minutes->getOriginal('status') === MinutesStatus::Approved->value || $minutes->getOriginal('status') === MinutesStatus::Approved) {
                throw new LogicException('Approved minutes are locked; correct them with new minutes.');
            }
        });

        static::deleting(function (self $minutes): void {
            if ($minutes->status === MinutesStatus::Approved) {
                throw new LogicException('Approved minutes cannot be deleted.');
            }
        });
    }

    public function isApproved(): bool
    {
        return $this->status === MinutesStatus::Approved;
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<self, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_minutes_id');
    }
}
