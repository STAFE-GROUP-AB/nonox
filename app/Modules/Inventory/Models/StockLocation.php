<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\LocationType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place goods are kept: a warehouse (with an address), and under it
 * zones, shelves and bins. full_code is the printable code for labels.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $parent_id
 * @property LocationType $type
 * @property string $code
 * @property string $full_code
 * @property string $sort_key
 * @property ?string $name
 * @property ?string $address_line1
 * @property ?string $postal_code
 * @property ?string $city
 * @property bool $is_active
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class StockLocation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'parent_id',
        'type',
        'code',
        'full_code',
        'sort_key',
        'name',
        'address_line1',
        'postal_code',
        'city',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<StockLocation, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<StockLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }

    /** The code and, when there is one, the name: "HL-A-03 Kylrum". */
    public function label(): string
    {
        return trim($this->full_code.' '.($this->name ?? ''));
    }
}
