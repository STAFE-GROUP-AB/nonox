<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A substitute for a date range (ADR-081 gap 8): while it runs, the
 * substitute may decide the steps that name the user, and the decision is
 * recorded "på uppdrag av" the user. Ended early by revoking it; the row is
 * kept, so the history shows why a substitute could decide.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $substitute_user_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $created_by
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 */
class ApprovalDelegation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'substitute_user_id',
        'starts_on',
        'ends_on',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Delegations in force on the day (not revoked, the day inside the range).
     *
     * @param  Builder<ApprovalDelegation>  $query
     * @return Builder<ApprovalDelegation>
     */
    public function scopeInForce(Builder $query, CarbonImmutable $day): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('starts_on', '<=', $day->toDateString())
            ->where('ends_on', '>=', $day->toDateString());
    }

    public function isInForce(CarbonImmutable $day): bool
    {
        return $this->revoked_at === null
            && $this->starts_on->toDateString() <= $day->toDateString()
            && $this->ends_on->toDateString() >= $day->toDateString();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_user_id');
    }
}
