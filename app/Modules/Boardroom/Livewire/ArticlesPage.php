<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Boardroom\Actions\SaveArticles;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\ShareClass;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Aktiebok → Bolagsordning: the versions of the articles of association
 * that NONOX uses (how notice is given, the short-notice clause, limits,
 * auditor, transfer clauses), each applied from its registration, and the
 * share classes with their votes.
 */
class ArticlesPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public bool $adopting = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, string> registration dates being entered, by version id */
    public array $registering = [];

    public ?int $editingClass = null;

    /** @var array<string, mixed> */
    public array $class = [];

    public function mount(): void
    {
        abort_unless($this->allowed('share-register:view'), 403);
    }

    public function adopt(): void
    {
        $previous = ArticlesVersion::query()->orderByDesc('version')->first();
        $this->adopting = true;
        $this->form = [
            'adopted_on' => CarbonImmutable::today()->toDateString(),
            'registered_on' => '',
            'seat' => $previous->seat ?? '',
            'notice_method' => $previous->notice_method ?? '',
            'short_notice_clause' => $previous->short_notice_clause ?? false,
            'share_capital_min' => '', 'share_capital_max' => '',
            'shares_min' => $previous->shares_min ?? '', 'shares_max' => $previous->shares_max ?? '',
            'board_min' => $previous->board_min ?? '', 'board_max' => $previous->board_max ?? '',
            'has_auditor' => $previous->has_auditor ?? false,
            'pre_emption_clause' => $previous->pre_emption_clause ?? false,
            'consent_clause' => $previous->consent_clause ?? false,
            'redemption_clause' => $previous->redemption_clause ?? false,
            'conversion_clause' => $previous->conversion_clause ?? false,
            'other_provisions' => $previous->other_provisions ?? '',
        ];
        $this->resetErrorBag();
    }

    public function save(SaveArticles $articles): void
    {
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        if ($this->attempt(fn () => $articles->adopt($this->user(), $this->company(), $input))) {
            $this->toast(__('Bolagsordningen är sparad som en ny version.'));
            $this->reset('adopting', 'form');
        }
    }

    public function register(int $id, SaveArticles $articles): void
    {
        $version = ArticlesVersion::query()->findOrFail($id);

        if ($this->attempt(fn () => $articles->registerVersion($this->user(), $this->company(), $version, (string) ($this->registering[$id] ?? '')), 'registering')) {
            $this->toast(__('Registreringsdagen är sparad. Versionen gäller från den dagen.'));
            unset($this->registering[$id]);
        }
    }

    public function editClass(?int $id = null): void
    {
        $existing = $id === null ? null : ShareClass::query()->findOrFail($id);
        $this->editingClass = $existing->id ?? 0;
        $this->class = [
            'code' => $existing->code ?? '',
            'name' => $existing->name ?? '',
            'votes' => $existing?->votesLabel() ?? '1',
            'restriction_text' => $existing->restriction_text ?? '',
        ];
        $this->resetErrorBag();
    }

    public function saveClass(SaveArticles $articles): void
    {
        $existing = $this->editingClass ? ShareClass::query()->findOrFail($this->editingClass) : null;

        if ($this->attempt(fn () => $articles->saveClass($this->user(), $this->company(), $this->class, $existing), 'class')) {
            $this->toast(__('Aktieslaget är sparat.'));
            $this->reset('editingClass', 'class');
        }
    }

    public function render(): View
    {
        return view('boardroom::livewire.articles', [
            'versions' => ArticlesVersion::query()->orderByDesc('version')->get(),
            'applicable' => ArticlesVersion::applicable(CarbonImmutable::today()),
            'classes' => ShareClass::query()->orderBy('code')->get(),
            'canManage' => $this->mayChange('share-register:manage'),
        ]);
    }
}
