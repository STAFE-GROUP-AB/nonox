<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\SalesDocument;
use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run's forecast for one production order (ADR-071 §13.3 steps 5–6).
 *
 * @property int $id
 * @property int $company_id
 * @property int $run_id
 * @property int $production_order_id
 * @property ?string $sales_document_id
 * @property ?CarbonImmutable $material_ready_on
 * @property ?CarbonImmutable $start_at
 * @property ?CarbonImmutable $finish_at
 * @property ?CarbonImmutable $forecast_delivery_on
 * @property ?CarbonImmutable $promised_on
 * @property ForecastStatus $status
 * @property WaitsFor $waits_for
 * @property list<string> $flags
 */
class ProductionPlanForecast extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['company_id', 'run_id', 'production_order_id', 'sales_document_id', 'material_ready_on', 'start_at', 'finish_at', 'forecast_delivery_on', 'promised_on', 'status', 'waits_for', 'flags'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'material_ready_on' => 'immutable_date',
            'start_at' => 'immutable_datetime',
            'finish_at' => 'immutable_datetime',
            'forecast_delivery_on' => 'immutable_date',
            'promised_on' => 'immutable_date',
            'status' => ForecastStatus::class,
            'waits_for' => WaitsFor::class,
            'flags' => 'array',
        ];
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
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
}
