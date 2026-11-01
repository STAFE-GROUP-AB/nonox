<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An API key for a script or a self-hosted integration (ADR-032): bound to
 * one company, with scopes and a mandatory expiry. Only a SHA-256 hash of
 * the key is stored; the key is shown once, when it is created.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property list<string> $scopes
 * @property ?list<string> $ip_allowlist
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $last_used_at
 * @property ?string $last_used_ip
 * @property int $created_by
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property CarbonImmutable $created_at
 */
class ConnectApiKey extends Model
{
    use BelongsToCompany;

    public const string PREFIX = 'nnx_';

    protected $table = 'connect_api_keys';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'prefix', 'token_hash', 'scopes', 'ip_allowlist', 'expires_at', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
