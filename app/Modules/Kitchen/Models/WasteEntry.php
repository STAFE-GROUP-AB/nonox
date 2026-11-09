<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Models\Article;
use App\Models\User;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Kitchen\Domain\WasteReason;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One line of the waste log (ADR-073 §6): an ingredient or a dish thrown
 * away or given away, with its reason. Its SCRAP movements carry it as
 * their source. Append-only: a wrong entry is corrected by a new entry
 * with the opposite quantity (corrects_entry_id), which puts the goods
 * back with RETURN movements.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $registered_on
 * @property string $kind
 * @property int $article_id
 * @property int $quantity_milli
 * @property int $location_id
 * @property WasteReason $reason
 * @property ?string $recipient
 * @property ?string $note
 * @property ?int $corrects_entry_id
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class WasteEntry extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    public const string INGREDIENT = 'INGREDIENT';

    public const string DISH = 'DISH';

    protected $table = 'kitchen_waste_entries';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'registered_on',
        'kind',
        'article_id',
        'quantity_milli',
        'location_id',
        'reason',
        'recipient',
        'note',
        'corrects_entry_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'registered_on' => 'immutable_date',
            'quantity_milli' => 'integer',
            'reason' => WasteReason::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The waste log cannot be changed; register a correction.');
        });

        static::deleting(function (): never {
            throw new LogicException('The waste log cannot be deleted; register a correction.');
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
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<self, $this> */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_entry_id');
    }

    public function isDish(): bool
    {
        return $this->kind === self::DISH;
    }
}
