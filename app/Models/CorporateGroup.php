<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared group with one parent company (ADR-060, addendum 2026-11-07).
 * Spans companies, so it is not company-scoped and holds no ledger data:
 * whether a user may see it is decided live by
 * App\Domain\Group\GroupAccess::levelFor(), never by this row.
 *
 * @property int $id
 * @property int $parent_company_id
 * @property string $name
 * @property ?int $created_by
 */
class CorporateGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['parent_company_id', 'name', 'created_by'];

    /** @return BelongsTo<Company, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_company_id');
    }

    /** @return HasMany<CorporateGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CorporateGroupMember::class);
    }

    /** @return HasMany<CorporateGroupRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(CorporateGroupRole::class);
    }
}
