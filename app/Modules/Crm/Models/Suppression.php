<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One e-mail address or phone number of a person who asked not to be
 * contacted, kept as a keyed hash after the person is erased, so the flag
 * comes back if the same person is added again (GDPR art. 21, ADR-043).
 * The value itself is never stored.
 *
 * @property int $id
 * @property int $company_id
 * @property string $kind
 * @property string $hash
 * @property string $reason
 */
class Suppression extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_suppressions';

    /** @var list<string> */
    protected $fillable = ['company_id', 'kind', 'hash', 'reason'];
}
