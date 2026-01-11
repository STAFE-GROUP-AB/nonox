<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /**
     * Fillable attributes for mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'org_number',
        'name',
        'address',
        'vat_number',
        'fiscal_year_start',
        'fiscal_year_end',
        'base_currency',
        'bankgiro',
        'plusgiro',
        'swish_number',
    ];

    /**
     * Casts for attribute types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_start' => 'date',
            'fiscal_year_end' => 'date',
        ];
    }

    /**
     * Get all parties (customers and suppliers) for this company.
     */
    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    /**
     * Get all items for this company.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Get all accounts for this company.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Get all quotes for this company.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Get all orders for this company.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all invoices for this company.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get all vouchers for this company.
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
