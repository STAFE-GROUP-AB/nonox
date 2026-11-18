<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approval\ApprovalDimension;
use App\Domain\Approval\ApproverKind;
use App\Domain\Approval\StepConditions;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an approval policy: when it applies, who decides it, how many
 * must approve and whether the preparer is kept out.
 *
 * @property int $id
 * @property int $company_id
 * @property int $approval_policy_id
 * @property int $position
 * @property array<string, mixed> $conditions
 * @property ApproverKind $approver_kind
 * @property ?string $approver_permission
 * @property ?list<int> $approver_user_ids
 * @property ?ApprovalDimension $approver_dimension
 * @property int $required_count
 * @property bool $exclude_preparer
 */
class ApprovalPolicyStep extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'approval_policy_id',
        'position',
        'conditions',
        'approver_kind',
        'approver_permission',
        'approver_user_ids',
        'approver_dimension',
        'required_count',
        'exclude_preparer',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'conditions' => 'array',
            'approver_kind' => ApproverKind::class,
            'approver_user_ids' => 'array',
            'approver_dimension' => ApprovalDimension::class,
            'required_count' => 'integer',
            'exclude_preparer' => 'boolean',
        ];
    }

    public function stepConditions(): StepConditions
    {
        return StepConditions::fromArray($this->conditions);
    }

    /** @return BelongsTo<ApprovalPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'approval_policy_id');
    }
}
