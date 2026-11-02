<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A VAT period that has been booked to the VAT account (2650). Never
 * changed; a database trigger backs this up. A period booked again after a
 * late posting gets a new version with the corrected boxes and its own
 * supplementary voucher (#63); the highest version is the current return.
 *
 * @property int $version
 * @property int $company_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property array<int, int> $boxes
 * @property string $voucher_id
 * @property int $created_by
 */
class VatReport extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'boxes',
        'version',
        'voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'boxes' => 'array',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('A reported VAT period cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /**
     * What the settlement vouchers of these reports have booked, per account
     * number, signed öre (debit positive). Needs voucher.rows.account loaded.
     *
     * @param  iterable<VatReport>  $reports
     * @return array<int, int>
     */
    public static function bookedRows(iterable $reports): array
    {
        $booked = [];

        foreach ($reports as $report) {
            foreach ($report->voucher->rows ?? [] as $row) {
                if (! $row->account instanceof Account) {
                    continue;
                }

                $number = $row->account->number;
                $booked[$number] = ($booked[$number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
            }
        }

        return $booked;
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
