<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An article: a product or service with its price excluding VAT, its VAT
 * rate and the revenue account it is booked on.
 *
 * @property int $company_id
 * @property string $number
 * @property string $name
 * @property string $unit
 * @property Money $price_ore
 * @property int $vat_rate
 * @property int $account_id
 * @property bool $is_active
 * @property ?string $sku
 * @property ?string $gtin
 * @property ?string $scanner_code
 * @property ?string $brand
 * @property ?string $model
 * @property ?string $description
 * @property ?array<int, array{key: string, value: string}> $properties
 * @property ?list<string> $colours
 * @property ?list<string> $materials
 * @property ?int $weight_grams
 * @property ?int $length_mm
 * @property ?int $width_mm
 * @property ?int $height_mm
 * @property ?int $purchase_price_ore
 * @property string $vat_treatment
 * @property ?int $default_supplier_id
 * @property ?string $supplier_article_number
 * @property bool $is_stock_item
 * @property ?int $reorder_point_milli
 * @property ?int $reorder_quantity_milli
 * @property ?int $default_location_id
 * @property ?int $net_realisable_value_ore
 * @property ?string $net_realisable_value_note
 * @property ?StockClass $stock_class
 * @property ?CarbonInterface $labels_printed_at
 * @property int $labels_printed_count
 */
class Article extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'external_ref',
        'name',
        'unit',
        'price_ore',
        'vat_rate',
        'account_id',
        'is_active',
        // The advanced fields (ADR-039); plain integers in öre and thousandths.
        'sku',
        'gtin',
        'scanner_code',
        'brand',
        'model',
        'description',
        'properties',
        'colours',
        'materials',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'purchase_price_ore',
        'vat_treatment',
        'default_supplier_id',
        'supplier_article_number',
        'is_stock_item',
        'reorder_point_milli',
        'reorder_quantity_milli',
        'default_location_id',
        'net_realisable_value_ore',
        'net_realisable_value_note',
        'stock_class',
    ];

    /** Normal VAT at the article's rate. */
    public const string VAT_STANDARD = 'STANDARD';

    /** Vinstmarginalbeskattning for used goods (VAT box 07). */
    public const string VAT_MARGIN = 'VMB';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_ore' => MoneyCast::class,
            'vat_rate' => 'integer',
            'is_active' => 'boolean',
            'properties' => 'array',
            'colours' => 'array',
            'materials' => 'array',
            'weight_grams' => 'integer',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'purchase_price_ore' => 'integer',
            'is_stock_item' => 'boolean',
            'reorder_point_milli' => 'integer',
            'reorder_quantity_milli' => 'integer',
            'net_realisable_value_ore' => 'integer',
            'stock_class' => StockClass::class,
            'labels_printed_at' => 'datetime',
            'labels_printed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<InvoiceRow, $this> */
    public function invoiceRows(): HasMany
    {
        return $this->hasMany(InvoiceRow::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'default_location_id');
    }

    /** @return HasMany<ArticleImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class)->orderBy('position');
    }

    /**
     * The first image, shown in lists (ADR-039).
     *
     * @return HasOne<ArticleImage, $this>
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ArticleImage::class)->ofMany(['position' => 'min', 'id' => 'min']);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
