<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Signing\Fields\PageImages;
use App\Models\SigningDocumentVersion;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Renders the pages of a document version with fields as images (ADR-046
 * amendment, gap 2, S6), in the background: after an envelope with fields is
 * sent, and after each signer's values are stamped into a new version. The
 * signer page only reads what is rendered; until then it says the pages
 * cannot be shown as images yet. Idempotent: a version already rendered, or
 * being rendered by another worker, is left alone.
 */
class RenderSigningPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 3;

    /** Seconds between tries: the engine may be busy or briefly missing. */
    public int $backoff = 60;

    /** A render of 100 pages may take a while; the render lock (PageImages::LOCK_SECONDS) outlives it. */
    public int $timeout = 300;

    public function __construct(public int $companyId, public int $versionId) {}

    public function handle(PageImages $images): void
    {
        CurrentCompany::run($this->companyId, function () use ($images): void {
            $version = SigningDocumentVersion::query()->find($this->versionId);

            if (! $version instanceof SigningDocumentVersion) {
                return;
            }

            // The lock is only tried (get(), never block()), so no LockTimeoutException can arise. While another
            // worker holds it the job is put back to look again later; an engine failure throws and is retried.
            if ($images->render($version) === null) {
                $this->release(30);
            }
        });
    }
}
