<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a deal between stages (ADR-043 stage 2), and the first row
 * when it is created (from_stage_id empty). The deal's history and the
 * sales cycle are read from these.
 *
 * @property int $id
 * @property int $company_id
 * @property int $deal_id
 * @property ?int $from_stage_id
 * @property ?int $to_stage_id
 * @property ?int $user_id
 * @property CarbonImmutable $changed_at
 */
class DealStageChange extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'crm_deal_stage_changes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'deal_id', 'from_stage_id', 'to_stage_id', 'user_id', 'changed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
