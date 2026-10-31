<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\ComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

/**
 * Ties every Livewire component to the company it was first rendered for
 * (AGENTS.md §1.3). The company id is kept in the component's memo, which
 * Livewire signs with the snapshot, so it cannot be changed in the browser.
 * If the user has switched company since (for example in another tab), a
 * later request from the old page answers 404 instead of showing the new
 * company's data in the old page.
 */
class LivewireCompanyGuard extends ComponentHook
{
    /** @param array<string, mixed> $memo */
    public function hydrate($memo): void
    {
        if ($this->component instanceof SpansCompanies || ! array_key_exists('company', $memo)) {
            return;
        }

        if ($memo['company'] !== $this->currentCompanyId()) {
            abort(404);
        }
    }

    /** @param ComponentContext $context */
    public function dehydrate($context): void
    {
        if ($this->component instanceof SpansCompanies) {
            return;
        }

        $context->addMemo('company', $this->currentCompanyId());
    }

    private function currentCompanyId(): ?int
    {
        $user = Auth::user();

        return $user instanceof User ? $user->current_team_id : null;
    }
}
