<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Tänk om" (ADR-071 §13.6): changes tried in memory without touching the
 * live plan, as ids, enums, dates and minutes. Applied, they are written
 * and a normal run follows. Expires after seven days.
 *
 * @property int $id
 * @property int $company_id
 * @property list<array<string, mixed>> $changes
 * @property ?int $created_by
 * @property ?CarbonImmutable $applied_at
 * @property ?int $applied_by
 * @property CarbonImmutable $expires_at
 */
class ProductionScenario extends Model
{
    use BelongsToCompany;

    public const int DAYS = 7;

    /** @var list<string> */
    protected $fillable = ['company_id', 'changes', 'created_by', 'applied_at', 'applied_by', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'applied_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('applied_at')->where('expires_at', '>', now());
    }
}
