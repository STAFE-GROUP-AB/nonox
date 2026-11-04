<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The secret behind a user's calendar link for a company's dates on Mina
 * datum (ADR-051, stage 2). Only the SHA-256 hash of the token is kept, so
 * the link is shown once when it is made; a new link or "Stäng av länken"
 * stops the old one at once. The same pattern as the CRM's (ADR-049).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $token_hash
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $revoked_at
 * @property CarbonImmutable $created_at
 */
class DeadlineCalendarFeed extends Model
{
    use BelongsToCompany;

    /** Letters and digits: 48 of them, about 285 bits. */
    public const int TOKEN_LENGTH = 48;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'token_hash'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
