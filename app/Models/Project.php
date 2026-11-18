<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectPriceForm;
use App\Domain\Projects\ProjectStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A projekt (AGENTS.md §6.5, SIE dimension 6), with its register details
 * (ADR-081 gap 6): customer, project manager, status, dates, price form,
 * contract sum, estimated total cost and a default cost centre for the rows
 * booked on it. The code never changes; the object can be renamed and
 * deactivated.
 *
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property ?int $customer_id
 * @property ?int $manager_user_id
 * @property ProjectStatus $status
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $ends_on
 * @property ?ProjectPriceForm $price_form
 * @property ?Money $contract_sum_ore
 * @property ?Money $estimated_cost_ore
 * @property ?int $default_cost_center_id
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Project extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'is_active',
        'customer_id',
        'manager_user_id',
        'status',
        'starts_on',
        'ends_on',
        'price_form',
        'contract_sum_ore',
        'estimated_cost_ore',
        'default_cost_center_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => ProjectStatus::class,
            'price_form' => ProjectPriceForm::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'contract_sum_ore' => MoneyCast::class,
            'estimated_cost_ore' => MoneyCast::class,
        ];
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function defaultCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'default_cost_center_id');
    }
}
