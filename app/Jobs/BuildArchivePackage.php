<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Audit\Audit;
use App\Support\Tenancy\RunsForCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds an ordered archive package in the background. Runs once: a failed
 * build has changed nothing, and the user can simply order a new package.
 */
class BuildArchivePackage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $companyId, public int $packageId) {}

    public function handle(ArchivePackageBuilder $builder): void
    {
        $started = ArchivePackage::query()
            ->whereKey($this->packageId)
            ->where('status', ArchivePackage::QUEUED)
            ->update(['status' => ArchivePackage::RUNNING]);

        if ($started !== 1) {
            return;
        }

        $package = ArchivePackage::query()->findOrFail($this->packageId);
        $user = User::query()->findOrFail($package->created_by);
        $fiscalYear = FiscalYear::query()->findOrFail($package->fiscal_year_id);

        // A large year may take minutes, also when the queue runs in the request,
        // and a report PDF needs some 200 MB (ArchivePackageBuilder::$rowsPerPdf).
        set_time_limit($this->timeout);
        self::ensureMemory(512 * 1024 * 1024);

        try {
            $result = $builder->build($user, $fiscalYear);
        } catch (Throwable $exception) {
            report($exception);
            $package->forceFill(['status' => ArchivePackage::FAILED, 'error' => __('Arkivpaketet kunde inte skapas. Försök igen, och hör av dig om det händer igen.')])->save();

            return;
        }

        $package->forceFill([
            'status' => ArchivePackage::DONE,
            'file_path' => $result['path'],
            'file_name' => $result['name'],
            'size' => $result['size'],
            'sha256' => $result['sha256'],
            'file_count' => $result['file_count'],
            'contents' => $result['contents'],
            'completed_at' => CarbonImmutable::now(),
        ])->save();
        Audit::record('archive.package_created', $package, ['fiscal_year' => $fiscalYear->label(), 'sha256' => $result['sha256'], 'files' => $result['file_count']], $this->companyId, $user);
    }

    /** Raises PHP's memory limit to at least the given bytes, never lowers it. */
    private static function ensureMemory(int $bytes): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $current = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($current < $bytes) {
            ini_set('memory_limit', (string) $bytes);
        }
    }
}
