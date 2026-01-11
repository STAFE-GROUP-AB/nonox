<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'source_quote_id',
        'order_number',
        'delivery_date',
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
            'delivery_date' => 'date',
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

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'source_quote_id');
    }
}
