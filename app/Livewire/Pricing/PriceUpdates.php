<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\PlanPriceUpdate;
use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceUpdateKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceUpdateRun;
use App\Models\User;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Mass price update, "Prisuppdatering" (ADR-082 §8, P6): choose which prices
 * and how they change, then go to the preview. Below the form, the updates
 * made before. Thin: PlanPriceUpdate does the work.
 */
class PriceUpdates extends Component
{
    use ShowsToasts;
    use WithFileUploads;
    use WithPagination;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $articleSearch = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [PriceUpdateRun::class, $this->company()]), 403);

        $this->form = [
            'kind' => 'PERCENT',
            'base' => true,
            'list_ids' => [],
            'file_target' => 'base',
            'articles' => 'all',
            'number_from' => '',
            'number_to' => '',
            'search' => '',
            'article_ids' => [],
            'percent' => '',
            'amount' => '',
            'index_old' => '',
            'index_new' => '',
            'rounding' => 'NONE',
            'effective_date' => CarbonImmutable::today()->toDateString(),
        ];
    }

    public function chooseArticle(int $articleId): void
    {
        $ids = array_map('intval', $this->form['article_ids'] ?? []);

        if (! in_array($articleId, $ids, true)) {
            $ids[] = $articleId;
        }

        $this->form['article_ids'] = $ids;
        $this->articleSearch = '';
    }

    public function removeArticle(int $articleId): void
    {
        $this->form['article_ids'] = array_values(array_filter(array_map('intval', $this->form['article_ids'] ?? []), fn (int $id): bool => $id !== $articleId));
    }

    public function plan(PlanPriceUpdate $plan): void
    {
        $file = $this->form['kind'] === 'FILE' && $this->file instanceof UploadedFile ? $this->file : null;
        $fileTarget = (string) ($this->form['file_target'] ?? 'base');

        $input = [
            ...$this->form,
            'base' => $this->form['kind'] === 'FILE' ? $fileTarget === 'base' : (bool) ($this->form['base'] ?? false),
            'list_ids' => $this->form['kind'] === 'FILE'
                ? ($fileTarget === 'base' ? [] : [(int) $fileTarget])
                : array_map('intval', $this->form['list_ids'] ?? []),
            'article_ids' => array_map('intval', $this->form['article_ids'] ?? []),
        ];

        $run = $plan($this->user(), $this->company(), $input, $file);

        Toast::flash(__('Förhandsgranskningen är klar. Inget är ändrat ännu.'));
        $this->redirectRoute('pricing.updates.show', ['priceUpdateRun' => $run->id]);
    }

    public function render(): View
    {
        $chosen = array_map('intval', $this->form['article_ids'] ?? []);

        return view('livewire.pricing.price-updates', [
            'canManage' => $this->user()->can('create', [PriceUpdateRun::class, $this->company()]),
            'kinds' => [PriceUpdateKind::Percent, PriceUpdateKind::Amount, PriceUpdateKind::Index, PriceUpdateKind::File],
            'roundings' => PriceRounding::cases(),
            'lists' => PriceList::query()->whereNull('archived_at')->orderBy('code')->get(['id', 'code', 'name', 'currency', 'kind']),
            'chosenArticles' => $chosen === [] ? collect() : Article::query()->whereKey($chosen)->orderBy('number')->get(['id', 'number', 'name']),
            'articleMatches' => $this->articleMatches(),
            'runs' => PriceUpdateRun::query()->with('creator:id,name')->latest('id')->paginate(15),
        ]);
    }

    /** @return Collection<int, Article> */
    private function articleMatches(): Collection
    {
        $search = trim($this->articleSearch);

        if ($search === '' || ($this->form['articles'] ?? '') !== 'selected') {
            return collect();
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        return Article::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'ilike', $like)->orWhere('number', 'ilike', $like))
            ->orderBy('number')->limit(8)->get(['id', 'number', 'name']);
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
