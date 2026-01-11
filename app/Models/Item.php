<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    /**
     * Fillable attributes for mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'item_number',
        'name',
        'description',
        'unit',
        'price_ex_vat',
        'vat_rate',
        'income_account',
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
            'price_ex_vat' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * Get the company that owns the item.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
