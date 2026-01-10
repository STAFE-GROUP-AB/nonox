<?php

namespace App\Models;

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
            'lines' => 'array',
            'immutable' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Validate that debit equals credit (double-entry bookkeeping).
     */
    public function validateDoubleEntry(): bool
    {
        $debitSum = 0;
        $creditSum = 0;

        foreach ($this->lines as $line) {
            $debitSum += $line['debit'] ?? 0;
            $creditSum += $line['credit'] ?? 0;
        }

        return round($debitSum, 2) === round($creditSum, 2);
    }
}
