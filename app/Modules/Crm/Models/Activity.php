<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something done or to be done with an account (ADR-043): a note, a call
 * with its outcome, a meeting, an e-mail logged by hand, or a task. Open
 * while done_at is empty; the open one due first is the account's next
 * step.
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_id
 * @property ?int $person_id
 * @property ?int $deal_id the deal this activity is a step of (ADR-043 stage 2)
 * @property ActivityType $type
 * @property ?CallOutcome $call_outcome
 * @property string $subject
 * @property ?string $body
 * @property ?int $assigned_to
 * @property ?CarbonImmutable $due_at
 * @property ?CarbonImmutable $done_at
 * @property ?int $created_by
 * @property ?int $duration_minutes a planned activity's length in a calendar (ADR-049); null takes the type's default
 * @property ?string $location where a meeting is held
 * @property int $calendar_sequence goes up on every change a calendar must see (ADR-049)
 * @property ?CarbonImmutable $invited_at when the meeting's person was last sent an invitation
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Activity extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_activities';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'account_id',
        'person_id',
        'deal_id',
        'type',
        'call_outcome',
        'subject',
        'body',
        'assigned_to',
        'due_at',
        'done_at',
        'created_by',
        'duration_minutes',
        'location',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'call_outcome' => CallOutcome::class,
            'due_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'calendar_sequence' => 'integer',
            'invited_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }

    public function isOverdue(CarbonImmutable $now): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lessThan($now);
    }
}
