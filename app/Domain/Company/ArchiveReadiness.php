<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Accounting\VoucherStatus;
use App\Models\ArchivePackage;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Before a company is archived (ADR-024): has every fiscal year an archive
 * package that is up to date, that is finished after the year's last
 * posting? Three queries, however many years.
 */
final class ArchiveReadiness
{
    /**
     * @return list<array{year: FiscalYear, package: ?ArchivePackage, current: bool, busy: bool}>
     */
    public function years(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $years = FiscalYear::query()->orderBy('start_date')->get();
            $latest = ArchivePackage::query()
                ->whereIn('id', ArchivePackage::query()->selectRaw('max(id)')->groupBy('fiscal_year_id'))
                ->get()
                ->keyBy('fiscal_year_id');
            $done = ArchivePackage::query()
                ->whereIn('id', ArchivePackage::query()->selectRaw('max(id)')->where('status', ArchivePackage::DONE)->groupBy('fiscal_year_id'))
                ->get()
                ->keyBy('fiscal_year_id');
            $lastPosted = Voucher::query()
                ->where('status', VoucherStatus::Posted->value)
                ->groupBy('fiscal_year_id')
                ->selectRaw('fiscal_year_id, max(posted_at) as last')
                ->pluck('last', 'fiscal_year_id')
                ->all();

            return array_values($years->map(function (FiscalYear $year) use ($latest, $done, $lastPosted): array {
                /** @var ?ArchivePackage $package */
                $package = $done->get($year->id);
                $last = isset($lastPosted[$year->id]) ? CarbonImmutable::parse((string) $lastPosted[$year->id]) : null;
                /** @var ?ArchivePackage $newest */
                $newest = $latest->get($year->id);

                return [
                    'year' => $year,
                    'package' => $package,
                    'current' => $package?->completed_at !== null && ($last === null || $package->completed_at->greaterThanOrEqualTo($last)),
                    'busy' => $newest?->isBusy() ?? false,
                ];
            })->all());
        });
    }

    /** Whether every fiscal year has an up-to-date archive package. */
    public function complete(Company $company): bool
    {
        return collect($this->years($company))->every(fn (array $row): bool => $row['current']);
    }
}
