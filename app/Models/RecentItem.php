<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A record the user opened, for "Senast öppnade" in the spotlight (ADR-026).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $type
 * @property string $record_id
 * @property string $title
 * @property ?string $subtitle
 * @property string $url
 * @property string $icon
 * @property CarbonImmutable $opened_at
 */
class RecentItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'type', 'record_id', 'title', 'subtitle', 'url', 'icon', 'opened_at'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
        ];
    }
}
