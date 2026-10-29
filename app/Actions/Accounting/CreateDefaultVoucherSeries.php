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

    public function __invoke(Company $company): int
    {
        return CurrentCompany::run($company, function (): int {
            $existing = VoucherSeries::query()->pluck('code')->all();
            $created = 0;

            foreach (self::SERIES as $code => $name) {
                if (in_array($code, $existing, true)) {
                    continue;
                }

                VoucherSeries::create(['code' => $code, 'name' => $name, 'is_default' => $code === 'A']);
                $created++;
            }

            return $created;
        });
    }
}
