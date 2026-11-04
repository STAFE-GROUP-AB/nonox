<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of Företagsguiden's start plan that the company has done
 * (ADR-051). The key is the step's `nyckel` in startplan.json; who ticked
 * it is stored as a user id only.
 *
 * @property int $id
 * @property int $company_id
 * @property string $step_key
 * @property CarbonImmutable $completed_at
 * @property ?int $completed_by
 * @property-read ?User $completer
 */
class CompanyGuideStep extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'step_key', 'completed_at', 'completed_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
