<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the stock ledger (ADR-039). Append-only: the model refuses
 * changes and a database trigger backs it up. A mistake is corrected by a
 * new movement. Written only through StockLedger, which keeps stock_levels
 * in step in the same transaction.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property int $location_id
 * @property int $quantity_milli
 * @property ?int $unit_cost_ore
 * @property MovementType $type
 * @property CarbonImmutable $occurred_on
 * @property ?string $source_type
 * @property ?string $source_id
 * @property ?string $transfer_group
 * @property ?string $reason
 * @property ?string $note
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'location_id',
        'quantity_milli',
        'unit_cost_ore',
        'type',
        'occurred_on',
        'source_type',
        'source_id',
        'transfer_group',
        'reason',
        'note',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_cost_ore' => 'integer',
            'type' => MovementType::class,
            'occurred_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Stock movements cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Stock movements cannot be deleted.');
        });
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
