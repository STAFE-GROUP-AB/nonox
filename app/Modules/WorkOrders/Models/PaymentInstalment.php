<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One instalment of a fixed-price work order's payment plan (ADR-074 §6):
 * an amount excluding VAT due on a date or at a milestone. Each becomes an
 * ordinary draft invoice with its own ROT or RUT on its own labour share.
 *
 * @property int $id
 * @property int $company_id
 * @property int $work_order_id
 * @property int $position
 * @property string $label
 * @property Money $amount_ore
 * @property ?CarbonImmutable $due_on
 * @property ?string $milestone
 */
class PaymentInstalment extends Model
{
    use BelongsToCompany;

    protected $table = 'work_order_payment_instalments';

    /** @var list<string> */
    protected $fillable = ['company_id', 'work_order_id', 'position', 'label', 'amount_ore', 'due_on', 'milestone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'amount_ore' => MoneyCast::class, 'due_on' => 'immutable_date'];
    }

    /** @return HasOne<WorkOrderInvoice, $this> */
    public function invoiceLink(): HasOne
    {
        return $this->hasOne(WorkOrderInvoice::class, 'instalment_id');
    }
}
