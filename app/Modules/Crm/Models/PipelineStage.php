<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An open stage of a pipeline (ADR-043 stage 2), with the probability, in
 * whole percent, that a deal there is won. The forecast weighs each deal's
 * value by it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pipeline_id
 * @property string $name
 * @property int $sort
 * @property int $probability
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class PipelineStage extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_pipeline_stages';

    /** @var list<string> */
    protected $fillable = ['company_id', 'pipeline_id', 'name', 'sort', 'probability'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'probability' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }
}
