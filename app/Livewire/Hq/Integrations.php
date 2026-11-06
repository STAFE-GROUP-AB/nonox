<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Support\Hq\HqAccess;
use App\Support\Integrations\IntegrationHealth;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Integrationer in /hq (ADR-034 and ADR-050, addenda 2026-11-06): whether
 * the bank feed and Peppol are configured, paused by the kill switch, and
 * how they are doing across the installation. Read only; the kill switch
 * itself is an environment setting, so it works even when the web app does
 * not.
 */
class Integrations extends Component implements SpansCompanies
{
    public function mount(): void
    {
        HqAccess::ensure(Auth::user());
    }

    public function render(IntegrationHealth $health): View
    {
        HqAccess::ensure(Auth::user());

        return view('livewire.hq.integrations', ['status' => $health()]);
    }
}
