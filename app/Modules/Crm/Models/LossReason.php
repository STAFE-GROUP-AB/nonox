<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Why a deal was lost (ADR-043 stage 2): the company's own list. An archived
 * reason stays on the deals that have it but is not offered again.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $sort
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class LossReason extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_loss_reasons';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'sort', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<LossReason>  $query
     * @return Builder<LossReason>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
