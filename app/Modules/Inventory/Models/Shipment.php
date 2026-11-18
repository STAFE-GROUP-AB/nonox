<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Försändelse: what was packed from a completed pick list, in packages, and
 * sent with a carrier. Stock is not touched here; it left with the pick list.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $pick_list_id
 * @property ?int $customer_id
 * @property ?string $sales_document_id
 * @property string $status
 * @property ?string $carrier
 * @property ?string $tracking_number
 * @property ?CarbonImmutable $shipped_at
 * @property ?string $ship_to
 * @property ?string $note
 * @property ?int $shipped_by
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class Shipment extends Model
{
    use BelongsToCompany;

    public const string DRAFT = 'DRAFT';

    public const string SHIPPED = 'SHIPPED';

    public const string CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'pick_list_id',
        'customer_id',
        'sales_document_id',
        'status',
        'carrier',
        'tracking_number',
        'shipped_at',
        'ship_to',
        'note',
        'shipped_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'shipped_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => __('Att packa'),
            self::SHIPPED => __('Skickad'),
            default => __('Makulerad'),
        };
    }

    /** @return HasMany<ShipmentPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<PickList, $this> */
    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }
}
