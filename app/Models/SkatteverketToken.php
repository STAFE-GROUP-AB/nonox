<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's sign-in with Skatteverket (ADR-054): personal, not a company's.
 * Skatteverket decides on every call whom the person may represent.
 *
 * @property int $id
 * @property int $user_id
 * @property string $access_token
 * @property ?string $refresh_token
 * @property string $scope
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $refresh_expires_at
 */
class SkatteverketToken extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'scope',
        'expires_at',
        'refresh_expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
