<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\ComponentHook;

/**
 * For a member limited to cost centres or projects (ADR-081 gap 10), only the
 * Livewire components DimensionScopeRegistry names render or answer; any
 * other answers 404, on the first render and on every later request. Routes
 * are guarded by EnforceDimensionScope; this covers components reached
 * through Livewire's own endpoint and components embedded in an open page.
 * A changed scope also changes the access fingerprint (LivewireCompanyGuard),
 * so a page kept open answers 404 until it is reloaded.
 */
class LivewireDimensionScopeGuard extends ComponentHook
{
    public function boot(): void
    {
        $user = Auth::user();
        $companyId = $user instanceof User ? CurrentCompany::id() : null;

        if (! $user instanceof User || $companyId === null || ! DimensionScope::limits($user, $companyId)) {
            return;
        }

        abort_if(DimensionScopeRegistry::component($this->component) === ScopeDecision::Refused, 404);
    }
}
