<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\GroupRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit role in a shared group (ADR-060, addendum 2026-11-07, 2.2).
 * It belongs to the holder's membership in the parent it was granted under
 * (GroupAccess). It is not a membership in any company: Memberships, CurrentCompany,
 * CompanyScope, policies and API credentials never read it.
 *
 * @property int $id
 * @property int $corporate_group_id
 * @property int $user_id
 * @property GroupRole $role
 * @property ?int $parent_membership_id team_user id of the holder's membership in the parent when granted; null for the owner
 * @property ?int $granted_by
 * @property CarbonImmutable $granted_at
 */
class CorporateGroupRole extends Model
{
    /** @var list<string> */
    protected $fillable = ['corporate_group_id', 'user_id', 'role', 'parent_membership_id', 'granted_by', 'granted_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'role' => GroupRole::class,
            'parent_membership_id' => 'integer',
            'granted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CorporateGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CorporateGroup::class, 'corporate_group_id');
    }
}
