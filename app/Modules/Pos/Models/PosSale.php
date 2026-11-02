<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Models\Voucher;
use App\Modules\Pos\Domain\Provider;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale or refund the till reported, recorded once (unique per company,
 * till provider and the till's id) and kept as it came: the payload is the
 * basis of the daily voucher and is kept with the books (ADR-042).
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property Provider $provider
 * @property string $external_id
 * @property string $source
 * @property bool $is_refund
 * @property CarbonImmutable $sold_at
 * @property CarbonImmutable $business_date
 * @property string $currency
 * @property int $total_ore
 * @property int $vat_ore
 * @property int $tips_ore
 * @property array<string, array{net: int, vat: int}> $vat_totals
 * @property array<string, int> $payments
 * @property list<string> $warnings
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property int $matched_count
 * @property int $unmatched_count
 * @property ?string $voucher_id
 */
class PosSale extends Model
{
    use BelongsToCompany;

    public const string WEBHOOK = 'WEBHOOK';

    public const string RECONCILE = 'RECONCILE';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'pos_connection_id',
        'provider',
        'external_id',
        'source',
        'is_refund',
        'sold_at',
        'business_date',
        'currency',
        'total_ore',
        'vat_ore',
        'tips_ore',
        'vat_totals',
        'payments',
        'warnings',
        'payload',
        'payload_hash',
        'matched_count',
        'unmatched_count',
        'voucher_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'is_refund' => 'boolean',
            'sold_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
            'vat_totals' => 'array',
            'payments' => 'array',
            'warnings' => 'array',
            'payload' => 'array',
        ];
    }

    /** @return HasMany<PosSaleRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(PosSaleRow::class)->orderBy('line_no');
    }

    /** @return BelongsTo<PosConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'pos_connection_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
