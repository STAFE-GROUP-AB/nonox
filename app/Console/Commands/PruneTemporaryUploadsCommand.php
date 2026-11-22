<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Removes Livewire's temporary uploads that are older than a day (ADR-046
 * S15 review). Livewire cleans only when some upload finishes, so a file
 * uploaded and then abandoned (a bulk send's address list, a PDF) could stay
 * for as long as nobody uploads. The disk and the folder are Livewire's own
 * configuration. Not for S3-like disks, which have their own lifecycle rules.
 */
class PruneTemporaryUploadsCommand extends Command
{
    public const int MAX_AGE_HOURS = 24;

    protected $signature = 'uploads:prune-temporary';

    protected $description = 'Tar bort Livewires tillfälliga uppladdningar som är äldre än 24 timmar.';

    public function handle(): int
    {
        if (FileUploadConfiguration::isUsingS3()) {
            $this->comment('Disken har egna livscykelregler; inget att ta bort här.');

            return self::SUCCESS;
        }

        $storage = FileUploadConfiguration::storage();
        $limit = now()->subHours(self::MAX_AGE_HOURS)->timestamp;
        $removed = 0;

        foreach ($storage->allFiles(FileUploadConfiguration::path()) as $file) {
            if ($storage->exists($file) && $storage->lastModified($file) < $limit) {
                $storage->delete($file);
                $removed++;
            }
        }

        $this->comment("Tog bort {$removed} tillfälliga uppladdningar.");

        return self::SUCCESS;
    }
}
