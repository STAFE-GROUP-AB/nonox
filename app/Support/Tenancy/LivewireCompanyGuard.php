<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\ComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

/**
 * Ties every Livewire component to the company it was first rendered for
 * (AGENTS.md §1.3). The company id is kept in the component's memo, which
 * Livewire signs with the snapshot, so it cannot be changed in the browser.
 * If the user has switched company since (for example in another tab), a
 * later request from the old page answers 404 instead of showing the new
 * company's data in the old page.
 *
 * The memo also keeps a fingerprint of the user's role in the company and
 * of the company's modules. Most components check permissions only when
 * they are first rendered; a page kept open after a demotion, or after a
 * module was switched off, would otherwise keep working (security review
 * 2026-11-01). The same goes for a page kept open while the company was
 * archived (ADR-024). When the fingerprint differs the request answers 404, and
 * reloading the page runs every check again.
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

        if (array_key_exists('access', $memo) && $memo['access'] !== $this->access()) {
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
        $context->addMemo('access', $this->access());
    }

    /** The user's role in the current company, the company's modules and whether it is archived, as one hash. */
    private function access(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return null;
        }

        $companyId = (int) $user->current_team_id;
        $row = DB::table('teams')
            ->leftJoin('team_user', fn ($join) => $join->on('team_user.team_id', '=', 'teams.id')->where('team_user.user_id', $user->id))
            ->where('teams.id', $companyId)
            ->first(['teams.user_id', 'teams.archived_at', 'team_user.role']);
        $role = $row === null ? '' : ((int) $row->user_id === $user->id ? 'owner' : (string) $row->role);
        $modules = app(ModuleManager::class);
        $enabled = array_filter(array_keys(app(ModuleRegistry::class)->all()), fn (string $key): bool => $modules->isEnabled($key, $companyId));

        $archived = $row !== null && $row->archived_at !== null ? 'archived' : '';

        return hash('sha256', $role.'|'.implode(',', $enabled).'|'.$archived);
    }

    private function currentCompanyId(): ?int
    {
        $user = Auth::user();

        return $user instanceof User ? $user->current_team_id : null;
    }
}
