<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A fee the till's provider reported (Zettle's Finance API, ADR-042):
 * positive is charged, negative refunded. It goes into the daily voucher.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property string $external_id
 * @property string $type
 * @property CarbonImmutable $business_date
 * @property int $amount_ore
 * @property array<string, mixed> $payload
 * @property ?string $voucher_id
 */
class PosFee extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_connection_id', 'external_id', 'type', 'business_date', 'amount_ore', 'payload', 'voucher_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'payload' => 'array',
        ];
    }
}
