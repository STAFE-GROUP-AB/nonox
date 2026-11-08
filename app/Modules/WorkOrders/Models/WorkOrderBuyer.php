<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\Customer;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of several ROT or RUT buyers on a work order with the share of the
 * labour (ADR-074 O6), copied to every invoice from the work order. The
 * buyer's personnummer stays on the customer, encrypted (AO-R38).
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property int $customer_id
 * @property int $share_bp
 */
class WorkOrderBuyer extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'work_order_id', 'customer_id', 'share_bp'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['share_bp' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
