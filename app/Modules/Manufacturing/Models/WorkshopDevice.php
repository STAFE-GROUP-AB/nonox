<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tablet in the workshop, paired to one company by a manager (ADR-071
 * §7). Only a SHA-256 hash of its token is stored; the token itself is
 * handed to the tablet once, at pairing. A revoked device is refused at
 * once. As the staff ledger's kiosk: the page holds no company data until
 * it is paired. A wall screen (M7) is a device of its own kind, paired the
 * same way.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property DeviceKind $kind
 * @property ?string $token_hash
 * @property ?string $pairing_code_hash
 * @property ?CarbonImmutable $pairing_expires_at
 * @property ?CarbonImmutable $paired_at
 * @property ?CarbonImmutable $last_seen_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property ?CarbonImmutable $removed_at
 * @property ?int $created_by
 */
class WorkshopDevice extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $hidden = ['token_hash', 'pairing_code_hash'];

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'kind', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DeviceKind::class,
            'pairing_expires_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, DeviceKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->token_hash !== null;
    }
}
