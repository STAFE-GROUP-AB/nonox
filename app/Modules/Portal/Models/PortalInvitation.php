<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation link (ADR-075 §3): only the SHA-256 of its 256-bit token
 * is stored; valid 14 days and once. Opening it signs nobody in.
 *
 * @property int $id
 * @property int $company_id
 * @property int $contact_id
 * @property string $token_sha256
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $used_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?CarbonImmutable $created_at
 */
class PortalInvitation extends Model
{
    use BelongsToCompany;

    public const int VALID_DAYS = 14;

    public const null UPDATED_AT = null;

    protected $table = 'customer_portal_invitations';

    /** @var list<string> */
    protected $fillable = ['company_id', 'contact_id', 'token_sha256', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_sha256'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<PortalContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(PortalContact::class, 'contact_id');
    }
}
