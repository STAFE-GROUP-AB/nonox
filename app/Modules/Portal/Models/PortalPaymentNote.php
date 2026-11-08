<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Models\Invoice;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Jag har betalat" (ADR-075 §5, decision O4): the customer's own note that
 * an invoice is paid on a date. It books nothing and changes nothing on the
 * invoice; the company sees it on the invoice and the portal shows it until
 * the payment has come in.
 *
 * @property int $id
 * @property int $company_id
 * @property string $invoice_id
 * @property ?int $contact_id
 * @property CarbonImmutable $paid_on
 * @property CarbonImmutable $created_at
 */
class PortalPaymentNote extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'customer_portal_payment_notes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'invoice_id', 'contact_id', 'paid_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<PortalContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(PortalContact::class, 'contact_id');
    }
}
