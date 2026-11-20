<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The article's tab Priser (ADR-082 §11, P4): the base price and its history,
 * every list that prices the article (now and later), and a lookup of the
 * price on a date and quantity.
 */
class ArticlePrices extends Component
{
    private const int LIMIT = 100;

    #[Locked]
    public int $articleId;

    public string $date = '';

    public string $quantity = '1';

    public function mount(int $articleId): void
    {
        $this->articleId = $articleId;

        abort_unless($this->user()->can('viewAny', [PriceList::class, $this->company()]), 403);

        $this->article();
        $this->date = CarbonImmutable::today()->toDateString();
    }

    public function render(PriceResolver $resolver): View
    {
        $article = $this->article();
        $today = CarbonImmutable::today()->toDateString();

        $items = PriceListItem::query()
            ->where('article_id', $article->id)
            ->with(['priceList:id,code,name,currency,kind,archived_at', 'unit:id,code'])
            ->orderBy('price_list_id')->orderBy('min_quantity_milli')->orderByDesc('valid_from')
            ->limit(self::LIMIT)
            ->get();

        return view('livewire.pricing.article-prices', [
            'article' => $article,
            'items' => $items,
            'today' => $today,
            'followers' => PriceList::query()->whereNull('archived_at')->whereNotNull('derived_percent_bp')->orderBy('code')->limit(self::LIMIT)->get(['id', 'code', 'name', 'derived_percent_bp', 'based_on_list_id']),
            'history' => ArticlePriceHistory::query()->where('article_id', $article->id)->orderByDesc('valid_from')->orderByDesc('id')->limit(15)->get(),
            'lookup' => $this->lookup($resolver, $article),
        ]);
    }

    /** @return array{price: ?string, source: ?string, error: ?string} */
    private function lookup(PriceResolver $resolver, Article $article): array
    {
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $this->date) ?: throw new InvalidArgumentException;
            $milli = Quantity::parse($this->quantity === '' ? '1' : $this->quantity);
        } catch (InvalidArgumentException) {
            return ['price' => null, 'source' => null, 'error' => __('Skriv ett datum och ett antal, till exempel 10.')];
        }

        $price = $resolver->resolve(new PriceContext((int) $this->company()->id, $date), new PriceRequest('lookup', $article->id, max(0, $milli)));

        if (! $price->hasPrice()) {
            return ['price' => null, 'source' => null, 'error' => __('Det finns inget pris för artikeln den dagen.')];
        }

        $text = Money::ofOre((int) $price->unitPriceMinor, $price->currency)->format();

        if ($price->discountBp > 0) {
            $text .= ' −'.PriceInput::basisPointsToPercent($price->discountBp).' %';
        }

        return ['price' => $text, 'source' => $price->source?->label(), 'error' => null];
    }

    private function article(): Article
    {
        return Article::query()->findOrFail($this->articleId);
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
