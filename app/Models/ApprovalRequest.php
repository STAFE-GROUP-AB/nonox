<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\PolicySnapshot;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One subject on its way through a company's own approval policy. The
 * policy is copied onto the request when it starts, so editing the policy
 * never changes a running request. Only the status, the current step and the
 * completion time change, and only while the request is pending; a database
 * trigger refuses the rest and every deletion.
 *
 * @property int $id
 * @property int $company_id
 * @property ApprovalSubjectType $subject_type
 * @property string $subject_id
 * @property ?int $approval_policy_id
 * @property array<string, mixed> $policy_snapshot
 * @property ApprovalRequestStatus $status
 * @property int $current_step
 * @property ?int $prepared_by
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class ApprovalRequest extends Model
{
    use BelongsToCompany;

    private const array MUTABLE = ['status', 'current_step', 'completed_at', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'subject_type',
        'subject_id',
        'approval_policy_id',
        'policy_snapshot',
        'prepared_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'PENDING',
        'current_step' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => ApprovalSubjectType::class,
            'policy_snapshot' => 'array',
            'status' => ApprovalRequestStatus::class,
            'current_step' => 'integer',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ApprovalRequest $request): void {
            if ($request->getOriginal('status') !== ApprovalRequestStatus::Pending
                || array_diff(array_keys($request->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException("Approval request {$request->id} cannot be changed.");
            }
        });

        static::deleting(function (ApprovalRequest $request): void {
            throw new LogicException("Approval request {$request->id} cannot be deleted.");
        });
    }

    public function snapshot(): PolicySnapshot
    {
        return PolicySnapshot::fromArray($this->policy_snapshot);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalRequestStatus::Pending;
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('decided_at')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
