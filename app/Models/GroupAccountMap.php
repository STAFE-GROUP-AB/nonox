<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one of a member company's accounts goes in a group's figures
 * (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E24). Identity is the default
 * and is never stored: a row exists only for a deviation, and only within
 * the same account class. The member company's own data, so it is
 * company-scoped (company_id = the company whose account is mapped); the
 * consolidation reads it inside that company. Written only by someone who
 * administers the group (MapGroupAccount).
 *
 * @property int $id
 * @property int $company_id
 * @property int $corporate_group_id
 * @property int $account_number
 * @property int $group_account_number
 * @property ?int $created_by
 */
class GroupAccountMap extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'corporate_group_id', 'account_number', 'group_account_number', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'corporate_group_id' => 'integer',
            'account_number' => 'integer',
            'group_account_number' => 'integer',
        ];
    }

    /** @return BelongsTo<CorporateGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CorporateGroup::class, 'corporate_group_id');
    }
}
