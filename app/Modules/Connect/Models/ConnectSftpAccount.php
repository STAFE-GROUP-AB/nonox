<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's SFTP drop for SIE files (ADR-032, "SFTP"). The user name is
 * generated and says nothing about the company; files that arrive are read
 * in as the responsible person, who must still hold connect:manage and
 * import:sie when they do.
 *
 * @property int $id
 * @property int $company_id
 * @property string $username
 * @property bool $is_enabled
 * @property int $responsible_user_id
 * @property list<string> $allowed_cidrs
 * @property ?CarbonImmutable $enabled_at
 * @property ?CarbonImmutable $disabled_at
 * @property ?CarbonImmutable $last_login_at
 * @property ?string $last_login_ip
 * @property ?CarbonImmutable $last_upload_at
 * @property ?CarbonImmutable $last_export_at
 * @property-read ?User $responsible
 */
class ConnectSftpAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'connect_sftp_accounts';

    /** @var list<string> */
    protected $fillable = ['company_id', 'username', 'is_enabled', 'responsible_user_id', 'allowed_cidrs', 'enabled_at', 'disabled_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['allowed_cidrs' => '[]', 'is_enabled' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'allowed_cidrs' => 'array',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'last_upload_at' => 'immutable_datetime',
            'last_export_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return HasMany<ConnectSftpKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(ConnectSftpKey::class, 'account_id');
    }

    /** @return HasMany<ConnectSftpUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(ConnectSftpUpload::class, 'account_id');
    }
}
