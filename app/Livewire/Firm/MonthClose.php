<?php

declare(strict_types=1);

namespace App\Livewire\Firm;

use App\Actions\Tasks\ToggleMonthCloseStep;
use App\Domain\Firm\FirmCache;
use App\Domain\Firm\Memberships;
use App\Domain\Firm\MonthCloseBoard;
use App\Domain\Tasks\MonthCloseStep;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag → Månadsstängning (ADR-028): every client's month, step by
 * step. Derived steps fill themselves in; the rest are checked off by hand.
 */
class MonthClose extends Component implements SpansCompanies
{
    use ShowsToasts;

    /** YYYY-MM; empty is the previous month, the one usually being closed. */
    #[Url(as: 'stangning', except: '')]
    public string $month = '';

    #[Url(as: 'ansvarig', except: '')]
    public string $responsible = '';

    public function toggle(int $companyId, string $step, ToggleMonthCloseStep $toggle): void
    {
        $memberships = Memberships::of($this->user());
        // A fresh model: one taken from the list of companies may not lazy load its members.
        $company = $memberships->has($companyId) ? Company::query()->find($companyId) : null;
        $case = MonthCloseStep::tryFrom($step);

        if (! $company instanceof Company || $case === null) {
            abort(404);
        }

        try {
            $checked = $toggle($this->user(), $company, $this->selected(), $case);
        } catch (AuthorizationException) {
            $this->toast(__('Du kan inte bocka av steg i :company.', ['company' => $company->name]), 'error');

            return;
        }

        $this->toast($checked
            ? __(':step är avbockat för :company.', ['step' => $case->label(), 'company' => $company->name])
            : __(':step är inte längre avbockat för :company.', ['step' => $case->label(), 'company' => $company->name]));
    }

    public function reload(): void
    {
        FirmCache::reload($this->user());
        $this->toast(__('Månadsstängningen är uppdaterad.'));
    }

    public function render(MonthCloseBoard $board): View
    {
        $month = $this->selected();
        $rows = $board->for($this->user(), $month);
        $consultants = collect($rows)->filter(fn (array $row): bool => $row['responsible_id'] !== null)
            ->mapWithKeys(fn (array $row): array => [$row['responsible_id'] => $row['responsible_name']])->sort()->all();

        if ($this->responsible !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $this->responsible === 'ingen' ? $row['responsible_id'] === null : (string) $row['responsible_id'] === $this->responsible));
        }

        return view('livewire.firm.month-close', [
            'months' => MonthCloseBoard::months(),
            'selected' => $month,
            'rows' => $rows,
            'steps' => MonthCloseStep::cases(),
            'consultants' => $consultants,
            'complete' => count(array_filter($rows, fn (array $row): bool => $row['total'] > 0 && $row['done'] === $row['total'])),
        ]);
    }

    private function selected(): string
    {
        $months = MonthCloseBoard::months();

        return in_array($this->month, $months, true) ? $this->month : $months[1];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
