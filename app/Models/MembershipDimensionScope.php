<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Company\ScopeDimension;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One cost centre or project a member is limited to (ADR-081 gap 10). The row
 * belongs to the membership it was set on (team_user_id); a later membership
 * of the same person never matches it, so a scope is never revived. Written
 * only by SetDimensionScope; read by DimensionScope.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $team_user_id
 * @property ScopeDimension $dimension
 * @property int $object_id
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class MembershipDimensionScope extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'team_user_id', 'dimension', 'object_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'team_user_id' => 'integer',
            'dimension' => ScopeDimension::class,
            'object_id' => 'integer',
        ];
    }
}
