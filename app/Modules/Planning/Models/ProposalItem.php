<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One open shift in a proposal: the proposed employee or none, the score
 * parts before weighting and the reasons as codes with ids and counts
 * (ProposalReasons). Personal data about how work is shared out: shown
 * only to planners, never ranked, deleted with the proposal.
 *
 * @property int $id
 * @property int $company_id
 * @property int $proposal_id
 * @property string $shift_id
 * @property ?int $employee_id
 * @property int $position
 * @property ?array<string, float> $score_parts
 * @property list<array<string, int|string>> $reasons
 * @property ?CarbonImmutable $accepted_at
 */
class ProposalItem extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_proposal_items';

    /** @var list<string> */
    protected $fillable = ['company_id', 'proposal_id', 'shift_id', 'employee_id', 'position', 'score_parts', 'reasons'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proposal_id' => 'integer',
            'employee_id' => 'integer',
            'position' => 'integer',
            'score_parts' => 'array',
            'reasons' => 'array',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isFilled(): bool
    {
        return $this->employee_id !== null;
    }
}
