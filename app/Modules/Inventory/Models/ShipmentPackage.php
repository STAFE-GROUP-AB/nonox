<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $shipment_id
 * @property int $position
 * @property ?int $weight_grams
 * @property ?int $length_mm
 * @property ?int $width_mm
 * @property ?int $height_mm
 */
class ShipmentPackage extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'shipment_id',
        'position',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'weight_grams' => 'integer',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return HasMany<ShipmentPackageLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentPackageLine::class)->orderBy('id');
    }
}
