<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The secret behind an employee's calendar link. Only the SHA-256 hash of
 * the token is kept, so the link is shown once when it is made; revoking it
 * stops the feed at once.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $token_hash
 * @property ?int $created_by
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $revoked_at
 * @property CarbonImmutable $created_at
 */
class IcalToken extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_ical_tokens';

    /** @var list<string> */
    protected $fillable = ['company_id', 'employee_id', 'token_hash', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
