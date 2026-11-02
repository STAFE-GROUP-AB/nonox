<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Company;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CurrentCompany;

/**
 * The default voucher series from AGENTS.md §6.2. Existing series are kept.
 */
class CreateDefaultVoucherSeries
{
    /** @var array<string, string> */
    public const array SERIES = [
        'A' => 'Manuell',
        'B' => 'Kundfakturor',
        'C' => 'Kundinbetalningar',
        'D' => 'Leverantörsfakturor',
        'E' => 'Leverantörsbetalningar',
        'L' => 'Lön',
        'M' => 'Moms',
        'I' => 'Ingående balanser/import',
    ];

    /**
     * Series a module adds when it is turned on, never by default: K for the
     * daily till sales (ADR-042). Not created for every company, so a SIE
     * file with its own series K still reads in as its own series.
     *
     * @var array<string, string>
     */
    public const array ON_DEMAND = [
        'K' => 'Kassa',
    ];

    /**
     * @param  ?list<string>  $codes  only these series (a module adding its own, ON_DEMAND included), else all default series
     */
    public function __invoke(Company $company, ?array $codes = null): int
    {
        return CurrentCompany::run($company, function () use ($codes): int {
            $existing = VoucherSeries::query()->pluck('code')->all();
            $created = 0;

            foreach ($codes === null ? self::SERIES : [...self::SERIES, ...self::ON_DEMAND] as $code => $name) {
                if (in_array($code, $existing, true) || ($codes !== null && ! in_array($code, $codes, true))) {
                    continue;
                }

                VoucherSeries::create(['code' => $code, 'name' => $name, 'is_default' => $code === 'A']);
                $created++;
            }

            return $created;
        });
    }
}
