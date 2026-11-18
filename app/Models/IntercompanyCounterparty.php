<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Group\IntercompanySide;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer or supplier of the company marked as a group company (ADR-060,
 * addendum 2026-11-07, 2.3). The other group company is named by
 * organisation number, never by another tenant's id; exactly one of
 * customer_id and supplier_id is set, and both belong to this company (a
 * CHECK and a trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $customer_id
 * @property ?int $supplier_id
 * @property string $counterparty_org_number formatted, NNNNNN-NNNN
 * @property ?int $created_by
 * @property-read ?Customer $customer
 * @property-read ?Supplier $supplier
 */
class IntercompanyCounterparty extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'customer_id', 'supplier_id', 'counterparty_org_number', 'created_by'];

    public function side(): IntercompanySide
    {
        return $this->customer_id !== null ? IntercompanySide::Customer : IntercompanySide::Supplier;
    }

    /** The marked customer's or supplier's name; needs customer and supplier loaded. */
    public function partyName(): string
    {
        return (string) ($this->customer_id !== null ? $this->customer?->name : $this->supplier?->name);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
