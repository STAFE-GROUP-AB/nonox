<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A schedule proposal (ADR-037 amendment, gap 3): who the engine proposes
 * for the open shifts of a period, made on demand by a planner. Nothing in
 * it changes the schedule until a person accepts all or some of it, and
 * then only as draft shifts. Accepted once; deleted after 90 days.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property array<string, mixed> $parameters
 * @property string $engine_version
 * @property int $slot_count
 * @property int $filled_count
 * @property int $swaps_kept
 * @property int $accepted_count
 * @property ?CarbonImmutable $accepted_at
 * @property ?int $accepted_by
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class Proposal extends Model
{
    use BelongsToCompany;

    /** Days a proposal is kept. */
    public const int RETENTION_DAYS = 90;

    protected $table = 'planning_proposals';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period_from',
        'period_to',
        'parameters',
        'engine_version',
        'slot_count',
        'filled_count',
        'swaps_kept',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'parameters' => 'array',
            'slot_count' => 'integer',
            'filled_count' => 'integer',
            'swaps_kept' => 'integer',
            'accepted_count' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'accepted_by' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ProposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class)->orderBy('position');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
