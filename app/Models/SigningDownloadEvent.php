<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One download from a completion link (ADR-046 S13): which file and when, so
 * the controller can see the access during an incident. No IP address, no
 * browser. Append-only (PostgreSQL trigger and grant); goes with its link.
 *
 * @property int $id
 * @property int $company_id
 * @property int $download_link_id
 * @property int $file_index
 * @property CarbonImmutable $downloaded_at
 */
class SigningDownloadEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'download_link_id',
        'file_index',
        'downloaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['downloaded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SigningDownloadLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(SigningDownloadLink::class, 'download_link_id');
    }
}
