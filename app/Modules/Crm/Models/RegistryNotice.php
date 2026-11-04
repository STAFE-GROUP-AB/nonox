<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * That a change in the company register has been turned into a task for
 * this account (ADR-047), so the same change is never reported twice.
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_id
 * @property int $registry_change_id
 * @property ?int $activity_id
 * @property ?CarbonImmutable $created_at
 */
class RegistryNotice extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_registry_notices';

    /** @var list<string> */
    protected $fillable = ['company_id', 'account_id', 'registry_change_id', 'activity_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
