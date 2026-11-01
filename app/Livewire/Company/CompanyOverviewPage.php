<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\SaveClientProfile;
use App\Domain\Company\CompanyOverview;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag: every company the user works in with what waits there, for
 * an accounting firm's clients or an owner's several companies. A task opens
 * the right page in its company.
 */
class CompanyOverviewPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'alla', except: false)]
    public bool $showAll = false;

    /** oversikt: totals and Att göra (ADR-028); stangning: month close; manad: the clients month by month; uppgifter: what waits. */
    #[Url(as: 'vy', except: 'oversikt')]
    public string $tab = 'oversikt';

    /** The month shown in the month view, YYYY-MM; empty is this month. */
    #[Url(as: 'manad', except: '')]
    public string $month = '';

    /** Only companies the user is responsible for. */
    #[Url(as: 'mina', except: false)]
    public bool $mine = false;

    public ?int $editingNote = null;

    public string $note = '';

    public function assign(int $companyId, string $responsibleId, SaveClientProfile $save): void
    {
        try {
            $save->assign($this->user(), $this->company($companyId), $responsibleId === '' ? null : (int) $responsibleId);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Ansvarig är sparad.'));
    }

    public function editNote(int $companyId, CompanyOverview $overview): void
    {
        $row = collect($overview->for($this->user()))->first(fn (array $row): bool => $row['company']->id === $companyId);
        $this->editingNote = $companyId;
        $this->note = (string) ($row['note'] ?? '');
    }

    public function saveNote(SaveClientProfile $save): void
    {
        if ($this->editingNote === null) {
            return;
        }

        $save->note($this->user(), $this->company($this->editingNote), $this->note);
        $this->reset('editingNote', 'note');
        $this->toast(__('Anteckningen är sparad. Den syns bara för redovisningskonsulterna.'));
    }

    public function render(CompanyOverview $overview): View
    {
        // The firm workspace (ADR-028) lives in its own components.
        if (in_array($this->tab, ['oversikt', 'stangning'], true)) {
            return view('livewire.company.overview-firm');
        }

        $user = $this->user();
        $needle = mb_strtolower(trim($this->search));
        $digits = (string) preg_replace('/\D/', '', $needle);
        $matches = fn (array $row): bool => $needle === ''
            || str_contains(mb_strtolower($row['company']->name), $needle)
            || ($digits !== '' && str_contains((string) preg_replace('/\D/', '', (string) $row['company']->org_number), $digits));

        if ($this->tab === 'manad') {
            $status = $overview->months($user, back: 2, ahead: 1);
            $month = in_array($this->month, $status['months'], true) ? $this->month : now()->format('Y-m');
            $weight = fn (array $row): int => collect([$row['cells'][$month]['vat'], $row['cells'][$month]['agi']])
                ->map(fn (?string $state): int => match ($state) {
                    'LATE' => 2,
                    'DUE' => 1,
                    default => 0,
                })->sum() + (($row['cells'][$month]['bank'] ?? 0) > 0 ? 1 : 0);
            $rows = collect($status['rows'])->filter($matches)
                ->sortBy([fn (array $a, array $b): int => $weight($b) <=> $weight($a), fn (array $a, array $b): int => strnatcasecmp($a['company']->name, $b['company']->name)])
                ->values()->all();

            return view('livewire.company.overview-months', [
                'months' => $status['months'],
                'selected' => $month,
                'rows' => $rows,
                'firm' => $this->firmName($user),
            ]);
        }

        $rows = $overview->for($user);

        $shown = array_values(array_filter($rows, fn (array $row): bool => ($this->showAll || $this->mine || $needle !== '' || $row['tasks'] !== [])
            && (! $this->mine || ($row['responsible']['id'] ?? null) === $user->id)
            && $matches($row)));

        return view('livewire.company.overview', [
            'rows' => $shown,
            'total' => count($rows),
            'waiting' => count(array_filter($rows, fn (array $row): bool => $row['tasks'] !== [])),
            'roleLabels' => collect(Jetstream::$roles)->mapWithKeys(fn ($role): array => [$role->key => $role->name])->put('owner', __('Ägare'))->all(),
            'currentId' => $user->current_team_id,
        ]);
    }

    /**
     * The firm the user works for, as the page heading names it: the
     * company the user owns when it is only one, otherwise the user.
     */
    private function firmName(User $user): string
    {
        $owned = $user->companies()->filter(fn (Company $company): bool => $user->ownsTeam($company));

        return $owned->count() === 1 ? (string) $owned->first()?->name : $user->name;
    }

    /** One of the user's own companies; another answers 404. */
    private function company(int $companyId): Company
    {
        return $this->user()->companies()->firstWhere('id', $companyId) ?? abort(404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
