<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workstation (ADR-071 §13.2): a lacquering booth, a saw, a press. It
 * serves some operations; an operation served by a workstation needs one of
 * them, at the same time as the person doing it. It is open whenever a
 * person works it, except while it is down. Archived, never deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ?string $code
 * @property ?CarbonImmutable $archived_at
 */
class Workstation extends Model
{
    use BelongsToCompany;

    protected $table = 'production_workstations';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'code', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return BelongsToMany<Operation, $this> */
    public function operations(): BelongsToMany
    {
        return $this->belongsToMany(Operation::class, 'production_workstation_operations', 'workstation_id', 'operation_id')->withPivot('company_id')->withTimestamps();
    }

    /** @return HasMany<Downtime, $this> */
    public function downtimes(): HasMany
    {
        return $this->hasMany(Downtime::class)->orderByDesc('starts_at');
    }
}
