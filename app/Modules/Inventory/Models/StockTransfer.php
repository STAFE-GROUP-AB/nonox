<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Flyttorder (ADR-081 E13): goods sent from one warehouse to another. DRAFT
 * is a plan; sending takes the goods out of the sending location into the
 * transit location of the pair, receiving puts what arrived into the
 * receiving location (partly, in several steps). Both are ordinary TRANSFER
 * movements, so the article's total and its value never change.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property ?int $transit_location_id
 * @property string $status
 * @property ?string $note
 * @property ?CarbonImmutable $sent_on
 * @property ?CarbonImmutable $sent_at
 * @property ?int $sent_by
 * @property ?CarbonImmutable $completed_at
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class StockTransfer extends Model
{
    use BelongsToCompany;

    public const string DRAFT = 'DRAFT';

    public const string SENT = 'SENT';

    public const string PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';

    public const string RECEIVED = 'RECEIVED';

    public const string CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'from_location_id',
        'to_location_id',
        'transit_location_id',
        'status',
        'note',
        'sent_on',
        'sent_at',
        'sent_by',
        'completed_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'sent_on' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => __('Utkast'),
            self::SENT => __('På väg'),
            self::PARTIALLY_RECEIVED => __('Delvis mottagen'),
            self::RECEIVED => __('Mottagen'),
            default => __('Avbruten'),
        };
    }

    /** Goods are on their way: sent and not yet fully received. */
    public function isInTransit(): bool
    {
        return in_array($this->status, [self::SENT, self::PARTIALLY_RECEIVED], true);
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function transit(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'transit_location_id');
    }
}
