<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\ComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use ReflectionObject;
use ReflectionProperty;

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
 *
 * A component may work on another company than the current one: the
 * settings page /foretag/{company} renders its forms for any company the
 * user belongs to. Such a component names its company in a #[Locked]
 * companyId or in a Company property; that company is kept in the memo with
 * its own fingerprint, and a later request answers 404 unless the user
 * still belongs to it with the same access (security review 2026-11-04).
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

        $target = $memo['target'] ?? null;

        if (! is_int($target) || $target === $memo['company']) {
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User || ! self::belongsTo($user, $target)) {
            abort(404);
        }

        if (($memo['target_access'] ?? null) !== AccessFingerprint::for($user, $target)) {
            abort(404);
        }
    }

    /** @param ComponentContext $context */
    public function dehydrate($context): void
    {
        if ($this->component instanceof SpansCompanies) {
            return;
        }

        $current = $this->currentCompanyId();

        $context->addMemo('company', $current);
        $context->addMemo('access', $this->access());

        $target = $this->targetCompanyId();
        $user = Auth::user();

        if ($target === null || $target === $current || ! $user instanceof User) {
            return;
        }

        $context->addMemo('target', $target);
        $context->addMemo('target_access', AccessFingerprint::for($user, $target));
    }

    /** The company the component itself names: a #[Locked] companyId, or a Company property (Livewire keeps a model's id fixed). */
    private function targetCompanyId(): ?int
    {
        foreach ((new ReflectionObject($this->component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($this->component)) {
                continue;
            }

            $value = $property->getValue($this->component);

            if ($value instanceof Company && $value->exists) {
                return (int) $value->getKey();
            }

            if ($property->getName() === 'companyId' && is_int($value) && $property->getAttributes(Locked::class) !== []) {
                return $value;
            }
        }

        return null;
    }

    private static function belongsTo(User $user, int $companyId): bool
    {
        return Company::query()
            ->whereKey($companyId)
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->orWhereHas('users', fn ($users) => $users->whereKey($user->id)))
            ->exists();
    }

    /** The user's role in the current company, the company's modules and whether it is archived, as one hash. */
    private function access(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return null;
        }

        return AccessFingerprint::for($user, (int) $user->current_team_id);
    }

    private function currentCompanyId(): ?int
    {
        $user = Auth::user();

        return $user instanceof User ? $user->current_team_id : null;
    }
}
