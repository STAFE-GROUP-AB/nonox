<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Signing\Pdf\NullPdfEngine;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Pdf\SidecarPdfEngine;
use Illuminate\Support\ServiceProvider;

/**
 * Chooses the PDF engine (ADR-046, amendment 2026-11-18, S5) from
 * nonox.signing.pdf_engine.driver: "sidecar" always uses the Python helper,
 * "none" never does, and "auto" (the default) uses it when the configured
 * Python and the helper script are both there. Without it, NONOX works as
 * before and the screens that need it say "Kräver PDF-motorn" (owner decision 4).
 */
class PdfEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PdfEngine::class, function (): PdfEngine {
            $driver = (string) config('nonox.signing.pdf_engine.driver', 'auto');

            if ($driver === 'none') {
                return new NullPdfEngine;
            }

            $sidecar = SidecarPdfEngine::fromConfig();

            if ($driver === 'sidecar' || $sidecar->isInstalled()) {
                return $sidecar;
            }

            return new NullPdfEngine;
        });

        $this->app->bind(PdfEngineStatus::class, fn ($app): PdfEngineStatus => new PdfEngineStatus($app->make(PdfEngine::class)));
    }
}
