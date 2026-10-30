<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier (leverantörsregister, AGENTS.md §7 Phase 2).
 *
 * @property int $company_id
 * @property int $number
 * @property string $name
 * @property ?string $org_number
 * @property ?string $vat_number
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $address_line1
 * @property ?string $postal_code
 * @property ?string $city
 * @property string $country
 * @property ?string $bankgiro
 * @property ?string $plusgiro
 * @property ?string $iban
 * @property ?string $bic
 * @property int $payment_terms_days
 * @property ?int $default_account_id
 * @property bool $is_active
 */
class Supplier extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'org_number',
        'vat_number',
        'email',
        'phone',
        'address_line1',
        'postal_code',
        'city',
        'country',
        'bankgiro',
        'plusgiro',
        'iban',
        'bic',
        'payment_terms_days',
        'default_account_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * How to pay the supplier, as printed on a payment: bankgiro first.
     */
    public function paymentDetails(): ?string
    {
        if (filled($this->bankgiro)) {
            return __('Bankgiro :number', ['number' => $this->bankgiro]);
        }

        if (filled($this->plusgiro)) {
            return __('Plusgiro :number', ['number' => $this->plusgiro]);
        }

        return filled($this->iban) ? trim("IBAN {$this->iban} {$this->bic}") : null;
    }

    /**
     * The supplier as it is now, kept on a registered invoice.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'number' => $this->number,
            ...$this->only(['name', 'org_number', 'vat_number', 'bankgiro', 'plusgiro', 'iban', 'bic']),
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }

    /** @return HasMany<SupplierInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
