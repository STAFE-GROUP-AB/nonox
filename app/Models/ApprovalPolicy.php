<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approval\ApprovalSubjectType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's own approval policy for one subject type (ADR-081 gap 8). The
 * lowest priority number is tried first; the first active policy with a step
 * that applies to the subject is followed. Every save raises the version; a
 * running request keeps the copy it started with.
 *
 * @property int $id
 * @property int $company_id
 * @property ApprovalSubjectType $subject_type
 * @property string $name
 * @property int $priority
 * @property bool $is_active
 * @property int $version
 * @property int $created_by
 * @property ?int $updated_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class ApprovalPolicy extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'subject_type',
        'name',
        'priority',
        'is_active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => ApprovalSubjectType::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<ApprovalPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalPolicyStep::class)->orderBy('position');
    }
}
