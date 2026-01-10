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
}
