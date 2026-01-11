<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quote extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'quote_number',
        'valid_until',
        'status',
        'rows',
        'total_ex_vat',
        'total_vat',
        'total_incl_vat',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'rows' => 'array',
            'total_ex_vat' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_incl_vat' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }
}
