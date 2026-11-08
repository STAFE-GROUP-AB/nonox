<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A code sent to the customer's own e-mail before an ÄTA's signing page
 * opens on the field worker's phone (ADR-074 O4). Only the code's hash is
 * stored; the signing link is kept encrypted until the code is verified
 * and then forgotten. Records who presented the page and on which device.
 *
 * @property int $id
 * @property int $company_id
 * @property int $change_order_id
 * @property int $signing_request_id
 * @property string $code_hash
 * @property ?string $url
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property ?CarbonImmutable $verified_at
 * @property int $presented_by
 * @property ?string $presented_user_agent
 */
class OnSiteCode extends Model
{
    use BelongsToCompany;

    public const int MINUTES = 15;

    public const int MAX_ATTEMPTS = 5;

    protected $table = 'change_order_on_site_codes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'change_order_id', 'signing_request_id', 'code_hash', 'url', 'expires_at', 'presented_by', 'presented_user_agent'];

    /** @var list<string> */
    protected $hidden = ['code_hash', 'url'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['url' => 'encrypted', 'expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
