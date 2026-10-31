<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Invoicing\ForeignSale;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer (kundregister, AGENTS.md §7 Phase 2).
 *
 * @property bool $reverse_charge_construction
 * @property ?ForeignSale $foreign_sale
 * @property int $company_id
 * @property int $number
 * @property string $name
 * @property ?string $org_number
 * @property ?string $personal_number
 * @property ?string $vat_number
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $address_line1
 * @property ?string $address_line2
 * @property ?string $postal_code
 * @property ?string $city
 * @property string $country
 * @property int $payment_terms_days
 * @property bool $is_active
 */
class Customer extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'org_number',
        'personal_number',
        'vat_number',
        'email',
        'phone',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'payment_terms_days',
        'is_active',
        'reverse_charge_construction',
        'foreign_sale',
    ];

    /** @var list<string> */
    protected $hidden = ['personal_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'personal_number' => 'encrypted',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
            'reverse_charge_construction' => 'boolean',
            'foreign_sale' => ForeignSale::class,
        ];
    }

    /**
     * The customer as printed on an invoice.
     *
     * @return array{number: int, name: string, org_number: ?string, vat_number: ?string, address_line1: ?string, address_line2: ?string, postal_code: ?string, city: ?string, country: string, email: ?string}
     */
    public function snapshot(): array
    {
        return [
            'number' => $this->number,
            'name' => $this->name,
            'org_number' => $this->org_number,
            'vat_number' => $this->vat_number,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,
            'email' => $this->email,
        ];
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
