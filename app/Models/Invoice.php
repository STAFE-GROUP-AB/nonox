<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'source_order_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'ocr_reference',
        'rows',
        'vat_summary',
        'total_ex_vat',
        'total_vat',
        'total_incl_vat',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'rows' => 'array',
            'vat_summary' => 'array',
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

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }
}
