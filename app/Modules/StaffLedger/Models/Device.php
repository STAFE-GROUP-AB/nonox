<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kiosk registered by a manager, bound to one company and one location.
 * Only a SHA-256 hash of its token is stored; the token itself is handed to
 * the kiosk once, at pairing. A revoked device is refused at once.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property string $name
 * @property ?string $token_hash
 * @property ?string $pairing_code_hash
 * @property ?CarbonImmutable $pairing_expires_at
 * @property ?CarbonImmutable $paired_at
 * @property ?CarbonImmutable $last_seen_at
 * @property int $last_sequence
 * @property ?string $last_client_hash
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property int $created_by
 */
class Device extends Model
{
    use BelongsToCompany;

    protected $table = 'staff_ledger_devices';

    /** @var list<string> */
    protected $hidden = ['token_hash', 'pairing_code_hash', 'last_client_hash'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_sequence' => 'integer',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->token_hash !== null;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
