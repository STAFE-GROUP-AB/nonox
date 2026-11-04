<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point of the year-end checklist that a person has confirmed, such as the
 * cash count. Removed again when unticked, as long as the year is open.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $key
 * @property int $checked_by
 * @property ?string $reason
 * @property ?string $fingerprint
 */
class ClosingCheck extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'key',
        'checked_by',
        'reason',
        'fingerprint',
    ];

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
