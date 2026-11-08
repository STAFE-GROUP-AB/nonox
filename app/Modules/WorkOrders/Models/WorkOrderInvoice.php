<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice made from a work order: from recorded time and material
 * (BASIS), an a conto instalment (INSTALMENT) or the remainder of a fixed
 * price (FINAL). Removed with the draft when the draft is deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property string $invoice_id
 * @property string $kind
 * @property ?int $instalment_id
 * @property Money $contract_net_ore the part of the contract sum it invoices, excluding VAT
 * @property ?list<int> $change_order_ids the fixed-price ÄTA whose amounts it invoices
 * @property int $labour_minutes the ROT/RUT labour minutes this invoice claims (review P1-2)
 */
class WorkOrderInvoice extends Model
{
    use BelongsToCompany;

    public const string BASIS = 'BASIS';

    public const string INSTALMENT = 'INSTALMENT';

    public const string FINAL = 'FINAL';

    /** @var list<string> */
    protected $fillable = ['company_id', 'work_order_id', 'invoice_id', 'kind', 'instalment_id', 'contract_net_ore', 'change_order_ids', 'labour_minutes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contract_net_ore' => MoneyCast::class, 'change_order_ids' => 'array', 'labour_minutes' => 'integer'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
