<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Which voucher in a SIE file became which voucher here. Keeps the file's own
 * series, number, registration date and signature, also when the voucher
 * had to get a new number. Never changed; a database trigger backs this up.
 *
 * @property int $sie_import_id
 * @property string $voucher_id
 * @property int $fiscal_year_id
 * @property string $original_series
 * @property string $original_number
 * @property ?CarbonImmutable $registered_on
 * @property ?string $signature
 */
class ImportedVoucher extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'sie_import_id',
        'voucher_id',
        'fiscal_year_id',
        'original_series',
        'original_number',
        'registered_on',
        'signature',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['registered_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Imported voucher records cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    public function originalReference(): string
    {
        return $this->original_series.$this->original_number;
    }
}
