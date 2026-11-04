<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\UpdateOpeningBalances;
use App\Domain\Accounting\StaleOpeningBalances;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The warning that opening balances no longer agree with the year before
 * (StaleOpeningBalances), with the differences per account on demand and the
 * button that updates them after a confirmation. Shown on Räkenskapsår for
 * every stale year, and on Rapporter for the year chosen there.
 */
class StaleOpeningBalancesNotice extends Component
{
    use ShowsToasts;

    /** Only this year when set (Rapporter); every stale year otherwise. */
    #[Reactive]
    public ?int $fiscalYearId = null;

    public function update(UpdateOpeningBalances $updateOpeningBalances): void
    {
        try {
            $labels = $updateOpeningBalances($this->user(), $this->company());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('De ingående balanserna för :years är uppdaterade.', ['years' => implode(', ', $labels)]));
        $this->dispatch('opening-balances-updated');
    }

    public function render(): View
    {
        $all = StaleOpeningBalances::for($this->company());
        $shown = $this->fiscalYearId === null
            ? $all
            : array_values(array_filter($all, fn (array $stale): bool => $stale['to_id'] === $this->fiscalYearId));

        return view('livewire.accounting.stale-opening-balances-notice', [
            'shown' => $shown,
            'years' => implode(', ', array_column($all, 'to')),
            'canUpdate' => $this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
