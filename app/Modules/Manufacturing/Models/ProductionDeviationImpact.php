<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one deviation did to one production order's delivery (ADR-071
 * §13.5): forecast before and after, against the promised date, and the
 * change in working days. A null forecast after means past the horizon.
 *
 * @property int $id
 * @property int $company_id
 * @property int $deviation_id
 * @property int $production_order_id
 * @property ?string $sales_document_id
 * @property ?int $customer_id
 * @property ?CarbonImmutable $promised_on
 * @property ?CarbonImmutable $forecast_before_on
 * @property ?CarbonImmutable $forecast_after_on
 * @property ?int $change_working_days
 */
class ProductionDeviationImpact extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'deviation_id', 'production_order_id', 'sales_document_id', 'customer_id', 'promised_on', 'forecast_before_on', 'forecast_after_on', 'change_working_days'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'promised_on' => 'immutable_date',
            'forecast_before_on' => 'immutable_date',
            'forecast_after_on' => 'immutable_date',
            'change_working_days' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
