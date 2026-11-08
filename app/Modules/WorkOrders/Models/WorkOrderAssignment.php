<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user assigned to a work order: a field worker sees only these (ADR-074 O3).
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property int $user_id
 */
class WorkOrderAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'work_order_users';

    /** @var list<string> */
    protected $fillable = ['company_id', 'work_order_id', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
