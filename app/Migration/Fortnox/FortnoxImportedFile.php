<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A file connected to a voucher in Fortnox, and whether it has been brought
 * over as an attachment to the imported voucher.
 *
 * @property int $id
 * @property int $company_id
 * @property string $file_id
 * @property int $fortnox_year
 * @property string $voucher_series
 * @property int $voucher_number
 * @property ?string $name
 * @property string $status PENDING, DONE or FAILED
 * @property ?string $voucher_id
 * @property ?string $message
 */
class FortnoxImportedFile extends Model
{
    use BelongsToCompany;

    public const string PENDING = 'PENDING';

    public const string DONE = 'DONE';

    public const string FAILED = 'FAILED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'file_id',
        'fortnox_year',
        'voucher_series',
        'voucher_number',
        'name',
        'status',
        'voucher_id',
        'message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fortnox_year' => 'integer',
            'voucher_number' => 'integer',
        ];
    }
}
