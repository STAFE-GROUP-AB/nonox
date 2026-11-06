<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company in a group, with the optional ownership share shown on the page.
 * Not company-scoped on purpose (ADR-060): reads go through GroupAccess.
 *
 * @property int $id
 * @property int $company_group_id
 * @property int $company_id
 * @property ?int $ownership_bp
 */
class CompanyGroupMember extends Model
{
    /** @var list<string> */
    protected $fillable = ['company_group_id', 'company_id', 'ownership_bp'];

    /** @return BelongsTo<CompanyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }
}
