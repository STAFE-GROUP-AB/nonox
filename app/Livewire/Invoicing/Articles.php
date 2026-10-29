<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\SaveArticle;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Articles: what the company sells, with price, VAT rate and revenue account.
 */
class Articles extends Component
{
    use ShowsToasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Article::class, $this->company()]), 403);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['number' => '', 'name' => '', 'unit' => 'st', 'price' => '', 'vat_rate' => '2500', 'account' => ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $articleId): void
    {
        $article = Article::query()->with('account')->findOrFail($articleId);

        $this->editingId = $article->id;
        $this->form = [
            'number' => $article->number,
            'name' => $article->name,
            'unit' => $article->unit,
            'price' => $article->price_ore->format(withCurrency: false),
            'vat_rate' => (string) $article->vat_rate,
            'account' => (string) $article->account?->number,
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form');
    }

    public function save(SaveArticle $save): void
    {
        $article = $save($this->user(), $this->company(), [
            ...$this->form,
            'vat_rate' => (int) ($this->form['vat_rate'] ?? 2500),
            'account' => ($this->form['account'] ?? '') === '' ? null : (int) $this->form['account'],
        ], $this->editingId === null ? null : Article::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __('Artikel :number :name är tillagd.', ['number' => $article->number, 'name' => $article->name])
            : __('Artikeln :name är sparad.', ['name' => $article->name]));

        $this->cancel();
    }

    public function render(): View
    {
        return view('livewire.invoicing.articles', [
            'articles' => Article::query()->with('account')->orderBy('number')->get(),
            'revenueAccounts' => Account::query()->whereBetween('number', [3000, 3999])->where('is_active', true)->orderBy('number')->get(['number', 'name']),
            'canManage' => $this->user()->can('create', [Article::class, $this->company()]),
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
