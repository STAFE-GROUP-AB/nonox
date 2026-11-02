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
 * An SSH public key that may log in to a company's SFTP drop (ADR-032,
 * "SFTP"). Only the public half is ever given to NONOX.
 *
 * @property string $id
 * @property int $company_id
 * @property int $account_id
 * @property string $name
 * @property string $algorithm
 * @property int $bits
 * @property string $public_key
 * @property string $fingerprint
 * @property int $created_by
 * @property ?CarbonImmutable $last_used_at
 * @property ?string $last_used_ip
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property CarbonImmutable $created_at
 * @property-read ?User $creator
 */
class ConnectSftpKey extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'connect_sftp_keys';

    /** @var list<string> */
    protected $fillable = ['company_id', 'account_id', 'name', 'algorithm', 'bits', 'public_key', 'fingerprint', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bits' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
