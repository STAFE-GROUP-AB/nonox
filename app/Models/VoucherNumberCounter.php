<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The next voucher number per company, fiscal year and series. Row-locked
 * while a voucher is posted, so numbers are gapless (AGENTS.md §6.4).
 *
 * @property int $next_number
 */
class VoucherNumberCounter extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'series_id',
        'next_number',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
