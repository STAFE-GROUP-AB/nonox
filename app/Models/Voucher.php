<?php

namespace App\Models;

use App\Services\VoucherValidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'company_id',
        'voucher_number',
        'date',
        'description',
        'lines',
        'source_type',
        'source_id',
        'immutable',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'lines' => 'array', // JSON används medvetet för flexibilitet i verifikationsrader
            'immutable' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Validate that debit equals credit (double-entry bookkeeping).
     * Delegates to VoucherValidationService for reusability.
     */
    public function validateDoubleEntry(): bool
    {
        $service = new VoucherValidationService();
        return $service->validateDoubleEntry($this->lines ?? []);
    }
}
