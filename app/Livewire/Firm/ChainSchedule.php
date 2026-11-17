<?php

declare(strict_types=1);

namespace App\Livewire\Firm;

use App\Actions\Tasks\SaveView;
use App\Domain\Firm\ChainSchedule as ChainScheduleData;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\SavedView;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag → Schema över företag (ADR-037 amendment, gap 8): one week of
 * shifts across the companies where the user may see the schedule, read
 * only. Nothing here changes data. A shift opens in its own company through
 * a logged company switch, as firm tasks do (ADR-028). The chosen companies
 * can be saved as named views, per user, in saved_views.
 */
class ChainSchedule extends Component implements SpansCompanies
{
    use ShowsToasts;

    public const string PAGE = 'planning-chain';

    public const int MAX_VALUE_LENGTH = 400;

    #[Url(as: 'vecka', except: '')]
    public string $week = '';

    /** @var list<string> the chosen company ids; none chosen means every company the user may see */
    #[Url(as: 'foretag', except: [])]
    public array $chosen = [];

    public string $viewName = '';

    public function previous(): void
    {
        $this->week = $this->monday()->subWeek()->toDateString();
    }

    public function next(): void
    {
        $this->week = $this->monday()->addWeek()->toDateString();
    }

    public function today(): void
    {
        $this->week = '';
    }

    public function clearChoice(): void
    {
        $this->chosen = [];
    }

    public function saveView(SaveView $save): void
    {
        $view = $save($this->user(), self::PAGE, $this->viewName, ['foretag' => implode(',', $this->chosenIds())], ['foretag'], self::MAX_VALUE_LENGTH);
        $this->viewName = '';
        $this->toast(__('Vyn ”:name” är sparad.', ['name' => $view->name]));
    }

    public function applyView(int $viewId): void
    {
        $view = SavedView::query()->where('user_id', $this->user()->id)->where('page', self::PAGE)->findOrFail($viewId);
        $this->chosen = array_values(array_filter(explode(',', (string) ($view->filters['foretag'] ?? '')), fn (string $id): bool => ctype_digit($id)));
    }

    public function deleteView(int $viewId, SaveView $save): void
    {
        $save->delete($this->user(), $viewId);
        $this->toast(__('Vyn är borttagen.'));
    }

    public function render(ChainScheduleData $chain): View
    {
        $user = $this->user();
        $monday = $this->monday();
        $data = $chain->for($user, $monday, $this->chosenIds());

        return view('livewire.firm.chain-schedule', [
            'data' => $data,
            'monday' => $monday,
            'views' => SavedView::query()->where('user_id', $user->id)->where('page', self::PAGE)->orderBy('name')->get(),
        ]);
    }

    /** @return list<int> */
    private function chosenIds(): array
    {
        // The browser can send anything in a public property, whatever the declared type says.
        $digits = array_filter($this->chosen, fn (mixed $id): bool => is_string($id) && ctype_digit($id)); // @phpstan-ignore function.alreadyNarrowedType

        return array_values(array_unique(array_map('intval', $digits)));
    }

    private function monday(): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->week, 'Europe/Stockholm') : false;
        $date = $date instanceof CarbonImmutable ? $date : CarbonImmutable::today('Europe/Stockholm');

        return $date->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
