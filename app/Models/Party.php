<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Party extends Model
{
    /**
     * Fillable attributes for mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'type',
        'name',
        'org_number',
        'personal_number',
        'vat_number',
        'address',
        'email',
        'payment_terms',
        'reference',
        'active',
    ];

    /**
     * Casts for attribute types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Get the company that owns the party.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    /**
     * Scope to filter customers only.
     */
    public function scopeCustomers($query)
    {
        return $query->where('type', 'customer');
    }
    
    /**
     * Scope to filter suppliers only.
     */
    public function scopeSuppliers($query)
    {
        return $query->where('type', 'supplier');
    }
}
