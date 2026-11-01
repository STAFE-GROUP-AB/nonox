<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consent (ADR-032): a user let an app into one company with some
 * scopes. Every code and token issued under it carries its id; revoking it
 * revokes them all.
 *
 * @property string $id
 * @property int $company_id
 * @property int $user_id
 * @property string $client_id
 * @property list<string> $scopes
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property ?string $revoke_reason
 * @property CarbonImmutable $created_at
 */
class ConnectGrant extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'connect_grants';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'client_id', 'scopes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ConnectApp, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ConnectApp::class, 'client_id');
    }
}
