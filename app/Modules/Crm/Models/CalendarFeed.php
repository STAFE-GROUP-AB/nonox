<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The secret behind a user's CRM calendar link in one company (ADR-049).
 * Only the SHA-256 hash of the token is kept, so the link is shown once
 * when it is made; a new link or "Stäng av" stops the old one at once.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $token_hash
 * @property bool $include_details
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $revoked_at
 * @property CarbonImmutable $created_at
 */
class CalendarFeed extends Model
{
    use BelongsToCompany;

    /** Letters and digits: 48 of them, about 285 bits. */
    public const int TOKEN_LENGTH = 48;

    protected $table = 'crm_calendar_feeds';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'token_hash', 'include_details'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = ['include_details' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'include_details' => 'boolean',
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
