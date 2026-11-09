<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Models\Customer;
use App\Modules\Saas\Domain\CustomerState;
use App\Modules\Saas\Domain\LineMonth;
use App\Modules\Saas\Domain\Movement;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's MRR at one month-end, the lines behind it and how it moved
 * (ADR-080 §3.3): mrr = opening + correction + fx + source + movement, to the öre.
 * Frozen with its month: a trigger refuses every change once the month is
 * closed.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $month
 * @property int $customer_id
 * @property int $opening_ore
 * @property int $correction_ore
 * @property int $fx_ore
 * @property int $source_ore
 * @property ?Movement $movement
 * @property int $movement_ore
 * @property int $reclass_ore
 * @property int $mrr_ore
 * @property CustomerState $state
 * @property ?CarbonImmutable $cohort_month
 * @property int $scheduled_churn_ore
 * @property ?CarbonImmutable $scheduled_churn_month
 * @property list<array<string, mixed>> $lines
 */
class SaasCustomerMonth extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'saas_customer_months';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'month',
        'customer_id',
        'opening_ore',
        'correction_ore',
        'fx_ore',
        'source_ore',
        'movement',
        'movement_ore',
        'reclass_ore',
        'mrr_ore',
        'state',
        'cohort_month',
        'scheduled_churn_ore',
        'scheduled_churn_month',
        'lines',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'customer_id' => 'integer',
            'opening_ore' => 'integer',
            'correction_ore' => 'integer',
            'fx_ore' => 'integer',
            'source_ore' => 'integer',
            'movement' => Movement::class,
            'movement_ore' => 'integer',
            'reclass_ore' => 'integer',
            'mrr_ore' => 'integer',
            'state' => CustomerState::class,
            'cohort_month' => 'immutable_date',
            'scheduled_churn_ore' => 'integer',
            'scheduled_churn_month' => 'immutable_date',
            'lines' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<LineMonth> */
    public function lineMonths(): array
    {
        return array_map(fn (array $line): LineMonth => LineMonth::fromArray($line), $this->lines);
    }

    /** The customer's amount in a category, signed as it moves MRR (a re-classified pause moves from Paus to Churn). */
    public function amountIn(Movement $movement): int
    {
        $amount = $this->movement === $movement ? $this->movement_ore : 0;

        return $amount + match ($movement) {
            Movement::Churn => -$this->reclass_ore,
            Movement::Pause => $this->reclass_ore,
            default => 0,
        };
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
