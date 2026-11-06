<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's named set of companies for internal group reporting (ADR-060).
 * Owned by the user, not by a company, so it is not company-scoped: it holds
 * company ids only, and every read filters them through the user's current
 * memberships (App\Domain\Group\GroupAccess).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class CompanyGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'name'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CompanyGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyGroupMember::class);
    }

    /** @return HasMany<CompanyGroupMark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(CompanyGroupMark::class);
    }
}
