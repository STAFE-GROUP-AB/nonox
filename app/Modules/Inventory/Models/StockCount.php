<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventering: a stocktake of a location and everything under it (or of
 * every location). The system quantity is captured when it starts;
 * completing it posts the differences as COUNT movements with a reason.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property ?int $location_id
 * @property string $status
 * @property CarbonImmutable $counted_on
 * @property ?string $note
 * @property ?CarbonImmutable $completed_at
 * @property ?int $completed_by
 * @property ?int $created_by
 */
class StockCount extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string COMPLETED = 'COMPLETED';

    public const string CANCELLED = 'CANCELLED';

    /** Reasons for a difference, for the movement and the audit trail. */
    public const array REASONS = ['COUNT_ERROR', 'DAMAGED', 'THEFT', 'NOT_REGISTERED', 'OTHER'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'location_id',
        'status',
        'counted_on',
        'note',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'counted_on' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            'COUNT_ERROR' => __('Fel vid tidigare räkning'),
            'DAMAGED' => __('Skadat eller utgånget'),
            'THEFT' => __('Svinn eller stöld'),
            'NOT_REGISTERED' => __('Inleverans eller försäljning inte registrerad'),
            'OTHER' => __('Annat'),
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => __('Pågår'),
            self::COMPLETED => __('Klar'),
            default => __('Makulerad'),
        };
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
