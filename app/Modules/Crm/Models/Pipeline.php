<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales pipeline (ADR-043 stage 2), one per business area if the company
 * wants several. Up to seven open stages; won and lost are the deal's
 * status. An archived pipeline keeps its deals but takes no new ones.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $sort
 * @property bool $is_default
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Pipeline extends Model
{
    use BelongsToCompany;

    /** The most open stages a pipeline may have: a short pipeline is one people keep up to date. */
    public const int MAX_STAGES = 7;

    protected $table = 'crm_pipelines';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'sort', 'is_default', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_default' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PipelineStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * @param  Builder<Pipeline>  $query
     * @return Builder<Pipeline>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Pipeline>  $query
     * @return Builder<Pipeline>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('sort')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
