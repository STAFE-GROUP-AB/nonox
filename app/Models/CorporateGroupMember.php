<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company in a shared group, confirmed by an owner or administrator of
 * that company (ADR-060, addendum 2026-11-07, 2.1). The parent is a member
 * without a holding. Not company-scoped on purpose: reads go through
 * App\Domain\Group\GroupAccess.
 *
 * @property int $id
 * @property int $corporate_group_id
 * @property int $company_id
 * @property ?int $holding_id
 * @property ?int $confirmed_by
 * @property CarbonImmutable $confirmed_at
 */
class CorporateGroupMember extends Model
{
    /** @var list<string> */
    protected $fillable = ['corporate_group_id', 'company_id', 'holding_id', 'confirmed_by', 'confirmed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'holding_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CorporateGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CorporateGroup::class, 'corporate_group_id');
    }
}
