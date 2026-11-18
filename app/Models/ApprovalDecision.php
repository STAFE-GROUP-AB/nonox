<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approval\ApprovalDecisionKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One person's decision on one step of an approval request: approve or
 * reject, with an optional reason, and the person a substitute decided for.
 * Append-only: the model and a database trigger refuse every change and
 * deletion, so the approval history stays as it was decided.
 *
 * @property int $id
 * @property int $company_id
 * @property int $approval_request_id
 * @property int $step
 * @property int $user_id
 * @property ?int $on_behalf_of
 * @property ApprovalDecisionKind $decision
 * @property ?string $reason
 * @property CarbonImmutable $decided_at
 */
class ApprovalDecision extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'approval_request_id',
        'step',
        'user_id',
        'on_behalf_of',
        'decision',
        'reason',
        'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'decision' => ApprovalDecisionKind::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ApprovalDecision $decision): void {
            throw new LogicException("Approval decision {$decision->id} cannot be changed.");
        });

        static::deleting(function (ApprovalDecision $decision): void {
            throw new LogicException("Approval decision {$decision->id} cannot be deleted.");
        });
    }

    /** Whose approval the decision counts as: the person decided for, or the decider. */
    public function principalId(): int
    {
        return $this->on_behalf_of ?? $this->user_id;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of');
    }
}
