<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A verifikationsserie, e.g. "A Manuell" (AGENTS.md §6.2).
 *
 * @property string $code
 * @property string $name
 * @property bool $is_default
 */
class VoucherSeries extends Model
{
    use BelongsToCompany;

    protected $table = 'voucher_series';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
