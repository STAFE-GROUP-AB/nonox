<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Support\Hq\HqAccess;
use App\Support\Integrations\IntegrationHealth;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Integrationer in /hq (ADR-034 and ADR-050, addenda 2026-11-06): whether
 * the bank feed and Peppol are configured, paused by the kill switch, and
 * how they are doing across the installation, and whether the optional PDF
 * engine for signing works (ADR-046 amendment 2026-11-18, S5; cached ten
 * minutes, `signing:engine-doctor` refreshes it). Read only; the kill switch
 * itself is an environment setting, so it works even when the web app does
 * not.
 */
class Integrations extends Component implements SpansCompanies
{
    public function mount(): void
    {
        HqAccess::ensure(Auth::user());
    }

    public function render(IntegrationHealth $health, PdfEngineStatus $pdfEngine): View
    {
        HqAccess::ensure(Auth::user());

        return view('livewire.hq.integrations', ['status' => $health(), 'pdfEngine' => $pdfEngine->current()]);
    }
}
