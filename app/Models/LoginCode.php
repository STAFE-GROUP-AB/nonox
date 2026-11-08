<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time sign-in code. Only an HMAC of the code is stored. Its audience
 * says where it works: WEB (NONOX's own sign-in) or PORTAL (a customer
 * portal, ADR-075); a code never works for the other.
 *
 * @property string $email
 * @property string $audience WEB or PORTAL
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $consumed_at
 * @property ?CarbonImmutable $created_at
 */
class LoginCode extends Model
{
    public const null UPDATED_AT = null;

    public const string WEB = 'WEB';

    public const string PORTAL = 'PORTAL';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'audience',
        'code_hash',
        'expires_at',
        'ip_address',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableFor(Builder $query, string $email, string $audience = self::WEB): Builder
    {
        return $query
            ->where('email', $email)
            ->where('audience', $audience)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }
}
