<?php

declare(strict_types=1);

namespace App\Livewire\Firm;

use App\Actions\Tasks\SaveView;
use App\Domain\Firm\FirmCache;
use App\Domain\Firm\FirmWorkspace;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\SavedView;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag → Översikt (ADR-028): totals across every company the user
 * works in, and one prioritised list of what to do, each item opening in its
 * own company. Filters live in the URL and can be saved as named views.
 */
class FirmOverview extends Component implements SpansCompanies
{
    use ShowsToasts;

    public const string PAGE = 'firm-overview';

    public const array FILTERS = ['ansvarig', 'frist', 'typ', 'status', 'mina'];

    #[Url(as: 'ansvarig', except: '')]
    public string $responsible = '';

    #[Url(as: 'frist', except: '')]
    public string $deadline = '';

    #[Url(as: 'typ', except: '')]
    public string $type = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'bara-mina', except: false)]
    public bool $mine = false;

    public string $viewName = '';

    public bool $showFigures = false;

    public function reload(): void
    {
        FirmCache::reload($this->user());
        $this->toast(__('Översikten är uppdaterad.'));
    }

    #[On('task-saved')]
    public function taskSaved(): void
    {
        // The new task bumped its company's version; the next render reads it.
    }

    public function clearFilters(): void
    {
        $this->reset('responsible', 'deadline', 'type', 'status', 'mine');
    }

    public function saveView(SaveView $save): void
    {
        $view = $save($this->user(), self::PAGE, $this->viewName, $this->filters(), self::FILTERS);
        $this->viewName = '';
        $this->toast(__('Vyn ”:name” är sparad.', ['name' => $view->name]));
    }

    public function applyView(int $viewId): void
    {
        $view = SavedView::query()->where('user_id', $this->user()->id)->where('page', self::PAGE)->findOrFail($viewId);
        $filters = $view->filters;
        $this->responsible = (string) ($filters['ansvarig'] ?? '');
        $this->deadline = (string) ($filters['frist'] ?? '');
        $this->type = (string) ($filters['typ'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->mine = ($filters['mina'] ?? '') === '1';
    }

    public function deleteView(int $viewId, SaveView $save): void
    {
        $save->delete($this->user(), $viewId);
        $this->toast(__('Vyn är borttagen.'));
    }

    public function render(FirmWorkspace $workspace): View
    {
        $user = $this->user();
        $data = $workspace->for($user);
        $items = FirmWorkspace::filter($data['items'], $this->filters(), $user);

        return view('livewire.firm.firm-overview', [
            'data' => $data,
            'items' => $items,
            'filtered' => array_filter($this->filters()) !== [],
            'types' => TaskType::cases(),
            'statuses' => TaskStatus::cases(),
            'views' => SavedView::query()->where('user_id', $user->id)->where('page', self::PAGE)->orderBy('name')->get(),
        ]);
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return [
            'ansvarig' => $this->responsible,
            'frist' => $this->deadline,
            'typ' => $this->type,
            'status' => $this->status,
            'mina' => $this->mine ? '1' : '',
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
