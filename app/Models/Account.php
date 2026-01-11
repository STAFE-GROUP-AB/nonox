<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    /**
     * Fillable attributes for mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'account_number',
        'name',
        'type',
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
     * Get the company that owns the account.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
