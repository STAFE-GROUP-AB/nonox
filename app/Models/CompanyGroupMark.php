<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account the user marks as intra-group in one company of a group, with
 * what it is (App\Domain\Group\MarkKind). Used only for elimination
 * suggestions; nothing is booked (ADR-060).
 *
 * @property int $id
 * @property int $company_group_id
 * @property int $company_id
 * @property int $account_number
 * @property string $kind
 */
class CompanyGroupMark extends Model
{
    /** @var list<string> */
    protected $fillable = ['company_group_id', 'company_id', 'account_number', 'kind'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['account_number' => 'integer'];
    }
}
