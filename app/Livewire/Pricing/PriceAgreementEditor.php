<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\DeletePriceAgreement;
use App\Actions\Pricing\NewPriceAgreementVersion;
use App\Actions\Pricing\RemovePriceAgreementItem;
use App\Actions\Pricing\ReopenPriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\TerminatePriceAgreement;
use App\Domain\Pricing\PriceAgreementPdf;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One price agreement (/prisavtal/{id}, ADR-082 §4, P5): its head, its terms
 * (only a draft's may change), how it became active, and the life-cycle
 * steps: send for signing (the shared signing panel), activate by hand with
 * a reason, take back to draft, make a new version, terminate, and delete a
 * draft. Every step is an action; this component only collects input and
 * tells the person what happened.
 */
class PriceAgreementEditor extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $agreementId;

    /** The head form of a draft. */
    public bool $editingHead = false;

    /** @var array<string, mixed> */
    public array $head = [];

    public bool $showItemForm = false;

    #[Locked]
    public ?int $editingItemId = null;

    /** @var array<string, mixed> */
    public array $item = [];

    public string $articleSearch = '';

    public bool $activating = false;

    public string $reason = '';

    public bool $terminating = false;

    public string $terminateOn = '';

    public function mount(int $agreementId): void
    {
        $this->agreementId = $agreementId;

        abort_unless($this->user()->can('view', $this->agreement()), 403);
    }

    /** The signing panel sent or withdrew the agreement: show its new status. */
    #[On('price-agreement-changed')]
    public function refreshStatus(): void {}

    public function editHead(): void
    {
        $agreement = $this->agreement();

        $this->head = [
            'customer_id' => (string) $agreement->customer_id,
            'title' => $agreement->title,
            'currency' => $agreement->currency,
            'valid_from' => $agreement->valid_from->toDateString(),
            'valid_to' => (string) $agreement->valid_to?->toDateString(),
            'allow_campaigns' => $agreement->allow_campaigns,
            'crm_agreement_id' => (string) $agreement->crm_agreement_id,
        ];
        $this->editingHead = true;
        $this->resetErrorBag();
    }

    public function cancelHead(): void
    {
        $this->reset('editingHead', 'head');
        $this->resetErrorBag();
    }

    public function saveHead(SavePriceAgreement $save): void
    {
        $save($this->user(), $this->company(), [
            ...$this->head,
            'customer_id' => (int) $this->head['customer_id'],
            'valid_to' => ($this->head['valid_to'] ?? '') === '' ? null : $this->head['valid_to'],
            'crm_agreement_id' => ($this->head['crm_agreement_id'] ?? '') === '' ? null : (int) $this->head['crm_agreement_id'],
        ], $this->agreement());

        $this->cancelHead();
        $this->toast(__('Avtalets villkor är sparade.'));
    }

    public function createItem(): void
    {
        $this->editingItemId = null;
        $this->item = ['target' => 'article', 'article_id' => '', 'unit_id' => '', 'min_quantity' => '', 'mode' => 'price', 'price' => '', 'discount' => '', 'base_price_list_id' => ''];
        $this->articleSearch = '';
        $this->showItemForm = true;
        $this->resetErrorBag();
    }

    public function editItem(int $itemId): void
    {
        $item = PriceAgreementItem::query()->where('price_agreement_id', $this->agreementId)->findOrFail($itemId);
        $agreement = $this->agreement();

        $this->editingItemId = $item->id;
        $this->item = [
            'target' => $item->applies_to_all ? 'all' : 'article',
            'article_id' => (string) $item->article_id,
            'unit_id' => (string) $item->unit_id,
            'min_quantity' => $item->min_quantity_milli === 0 ? '' : Quantity::decimal($item->min_quantity_milli),
            'mode' => $item->discount_bp === null ? 'price' : 'discount',
            'price' => $item->price_minor === null ? '' : Money::ofOre($item->price_minor, $agreement->currency)->format(withCurrency: false),
            'discount' => $item->discount_bp === null ? '' : PriceInput::basisPointsToPercent($item->discount_bp),
            'base_price_list_id' => (string) $item->base_price_list_id,
        ];
        $this->articleSearch = '';
        $this->showItemForm = true;
        $this->resetErrorBag();
    }

    public function updatedItemTarget(string $target): void
    {
        if ($target === 'all') {
            $this->item['mode'] = 'discount';
            $this->item['article_id'] = '';
            $this->item['unit_id'] = '';
        }
    }

    public function chooseArticle(int $articleId): void
    {
        $this->item['article_id'] = (string) Article::query()->findOrFail($articleId)->id;
        $this->item['unit_id'] = '';
        $this->articleSearch = '';
    }

    public function clearArticle(): void
    {
        $this->item['article_id'] = '';
        $this->item['unit_id'] = '';
    }

    public function cancelItem(): void
    {
        $this->reset('showItemForm', 'editingItemId', 'item', 'articleSearch');
        $this->resetErrorBag();
    }

    public function saveItem(SavePriceAgreementItem $save): void
    {
        $existing = $this->editingItemId === null ? null : PriceAgreementItem::query()->where('price_agreement_id', $this->agreementId)->findOrFail($this->editingItemId);

        $save($this->user(), $this->company(), $this->agreement(), [
            ...$this->item,
            'article_id' => ($this->item['article_id'] ?? '') === '' ? null : (int) $this->item['article_id'],
            'unit_id' => ($this->item['unit_id'] ?? '') === '' ? null : (int) $this->item['unit_id'],
            'base_price_list_id' => ($this->item['base_price_list_id'] ?? '') === '' ? null : (int) $this->item['base_price_list_id'],
        ], $existing);

        $this->toast($existing === null ? __('Priset är tillagt i avtalet.') : __('Priset i avtalet är sparat.'));
        $this->cancelItem();
    }

    public function removeItem(int $itemId, RemovePriceAgreementItem $remove): void
    {
        $item = PriceAgreementItem::query()->where('price_agreement_id', $this->agreementId)->findOrFail($itemId);

        $this->attempt(fn () => $remove($this->user(), $this->company(), $this->agreement(), $item), __('Priset är borttaget ur avtalet.'));
    }

    public function startActivation(): void
    {
        $this->activating = true;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancelActivation(): void
    {
        $this->reset('activating', 'reason');
        $this->resetErrorBag();
    }

    public function activate(ActivatePriceAgreement $activate): void
    {
        $agreement = $activate($this->user(), $this->company(), $this->agreement(), $this->reason);

        $this->cancelActivation();
        $this->toast(__('Prisavtalet :reference är aktivt. Fakturor, offerter och order till kunden får nu avtalets priser.', ['reference' => $agreement->reference()]));
    }

    public function reopen(ReopenPriceAgreement $reopen): void
    {
        $this->attempt(fn () => $reopen($this->user(), $this->company(), $this->agreement()), __('Avtalet är ett utkast igen. Länkarna för signering är återkallade.'));
    }

    public function newVersion(NewPriceAgreementVersion $version): void
    {
        try {
            $new = $version($this->user(), $this->company(), $this->agreement());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Ny version :reference är skapad som utkast. Ändra priserna och skicka den för signering; den gamla gäller tills den nya börjar.', ['reference' => $new->reference()]));
        $this->redirectRoute('pricing.agreements.show', ['priceAgreement' => $new->id], navigate: false);
    }

    public function startTermination(): void
    {
        $this->terminating = true;
        $this->terminateOn = CarbonImmutable::today()->endOfMonth()->toDateString();
        $this->resetErrorBag();
    }

    public function cancelTermination(): void
    {
        $this->reset('terminating', 'terminateOn');
        $this->resetErrorBag();
    }

    public function terminate(TerminatePriceAgreement $terminate): void
    {
        $agreement = $terminate($this->user(), $this->company(), $this->agreement(), $this->terminateOn);

        $this->cancelTermination();
        $this->toast(__('Avtalet är uppsagt och gäller till och med :date.', ['date' => $agreement->terminated_on?->toDateString()]));
    }

    public function delete(DeletePriceAgreement $delete): void
    {
        try {
            $delete($this->user(), $this->company(), $this->agreement());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Utkastet är borttaget.'));
        $this->redirectRoute('pricing.agreements', navigate: false);
    }

    public function render(): View
    {
        $agreement = $this->agreement()->load(['customer:id,name,number', 'supersedes:id,number', 'crmAgreement:id,title,end_date']);
        $user = $this->user();
        $article = ($this->item['article_id'] ?? '') === '' ? null : Article::query()->with('stockUnit')->find((int) $this->item['article_id']);
        $crm = app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company());
        $sent = $agreement->hasBeenSent();

        return view('livewire.pricing.price-agreement-editor', [
            'agreement' => $agreement,
            'items' => PriceAgreementPdf::items($agreement),
            'canManage' => $user->can('update', $agreement),
            'canChangeTerms' => $user->can('changeTerms', $agreement),
            'canDelete' => $user->can('delete', $agreement) && ! $sent,
            'crm' => $crm,
            'supersededBy' => PriceAgreement::query()->where('supersedes_id', $agreement->id)->orderByDesc('id')->first(['id', 'number', 'status']),
            'activatedBy' => $agreement->activated_by === null ? null : User::query()->whereKey($agreement->activated_by)->value('name'),
            'showSigning' => $sent || in_array($agreement->status, [PriceAgreementStatus::Draft, PriceAgreementStatus::AwaitingSignature], true),
            'article' => $article,
            'articleMatches' => $this->showItemForm && $article === null && ($this->item['target'] ?? 'article') === 'article' ? $this->articleMatches() : collect(),
            'unitChoices' => $article === null ? collect() : $this->unitChoices($article),
            'listChoices' => $this->showItemForm ? PriceList::query()->whereNull('archived_at')->where('kind', '<>', PriceListKind::Campaign->value)->where('currency', $agreement->currency)->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'crmChoices' => $this->editingHead && $crm ? $this->crmChoices($agreement) : collect(),
            'today' => CarbonImmutable::today(),
        ]);
    }

    private function attempt(callable $step, string $done): void
    {
        try {
            $step();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($done);
    }

    /** @return Collection<int, Article> */
    private function articleMatches(): Collection
    {
        $search = trim($this->articleSearch);

        if ($search === '') {
            return collect();
        }

        return Article::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"))
            ->orderBy('number')->limit(8)->get(['id', 'number', 'name', 'unit', 'price_ore']);
    }

    /** @return Collection<int, Unit> the units the article's prices may be given in */
    private function unitChoices(Article $article): Collection
    {
        $stock = ArticleUnits::stockCode($article);
        $conversions = ArticleUnits::conversions($article);

        return Unit::query()->whereNull('archived_at')->orderBy('code')->get()
            ->filter(fn (Unit $unit): bool => UnitConverter::enteredPerStock($stock, $unit->code, $conversions) !== null)
            ->values();
    }

    /** @return Collection<int, Agreement> */
    private function crmChoices(PriceAgreement $agreement): Collection
    {
        $accountIds = Account::query()->where('customer_id', $agreement->customer_id)->pluck('id');

        return $accountIds->isEmpty() ? collect() : Agreement::query()->whereIn('account_id', $accountIds)->orderBy('title')->get(['id', 'title', 'end_date']);
    }

    private function agreement(): PriceAgreement
    {
        return PriceAgreement::query()->findOrFail($this->agreementId);
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
