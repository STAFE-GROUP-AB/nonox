<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who signs in to customer portals (ADR-075 §2): an e-mail address
 * and a name, nothing else, and never a NONOX user. Global like users: one
 * identity can be a contact for customers of many companies. No password
 * and no remember-me token; the portal guard signs it in after an e-mail
 * code. Deleted when it has no contacts left.
 *
 * @property int $id
 * @property string $email
 * @property string $name
 * @property ?CarbonImmutable $sessions_valid_from
 * @property ?CarbonImmutable $last_login_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class PortalIdentity extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'portal_identities';

    /** @var list<string> */
    protected $fillable = ['email', 'name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sessions_valid_from' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** No remember-me: the session ends after 30 minutes idle or 12 hours. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    /** @return HasMany<PortalContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(PortalContact::class);
    }
}
