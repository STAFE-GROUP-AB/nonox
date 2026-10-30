<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\SaveClientProfile;
use App\Domain\Company\CompanyOverview;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
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
class CompanyOverviewPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'alla', except: false)]
    public bool $showAll = false;

    /** uppgifter: what waits; manader: month by month. */
    #[Url(as: 'vy', except: 'uppgifter')]
    public string $tab = 'uppgifter';

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
        $user = $this->user();
        $needle = mb_strtolower(trim($this->search));
        $digits = (string) preg_replace('/\D/', '', $needle);
        $matches = fn (array $row): bool => $needle === ''
            || str_contains(mb_strtolower($row['company']->name), $needle)
            || ($digits !== '' && str_contains((string) preg_replace('/\D/', '', (string) $row['company']->org_number), $digits));

        if ($this->tab === 'manader') {
            $status = $overview->months($user);

            return view('livewire.company.overview-months', [
                'months' => $status['months'],
                'rows' => array_values(array_filter($status['rows'], $matches)),
                'currentId' => $user->current_team_id,
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
