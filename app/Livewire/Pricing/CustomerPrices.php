<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\AssignCustomerPrices;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The customer's tab Priser (ADR-082 §11, P4): which price group and own
 * list the customer has, and what the customer pays for each article today,
 * worked out by the same PriceResolver the documents use.
 */
class CustomerPrices extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Locked]
    public int $customerId;

    public string $groupId = '';

    public string $listId = '';

    public string $search = '';

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;

        abort_unless($this->user()->can('viewAny', [PriceList::class, $this->company()]), 403);

        $customer = $this->customer();
        $this->groupId = (string) $customer->price_group_id;
        $this->listId = (string) $customer->price_list_id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function save(AssignCustomerPrices $assign): void
    {
        try {
            $assign(
                $this->user(),
                $this->company(),
                $this->customer(),
                $this->groupId === '' ? null : (int) $this->groupId,
                $this->listId === '' ? null : (int) $this->listId,
            );
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Kundens priser är sparade.'));
    }

    public function render(PriceResolver $resolver): View
    {
        $customer = $this->customer();
        $search = trim($this->search);

        $articles = Article::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('price_ore', '>', 0)->orWhereIn('id', PriceListItem::query()->whereNull('archived_at')->select('article_id')))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%")))
            ->orderBy('number')
            ->paginate(10);

        $context = new PriceContext((int) $this->company()->id, CarbonImmutable::today(), $customer->currency ?: 'SEK', $customer->id);
        $resolved = $resolver->resolveMany($context, array_values(array_map(
            fn (Article $article): PriceRequest => new PriceRequest((string) $article->id, $article->id),
            $articles->items(),
        )));

        $listIds = collect($resolved)->map(fn ($price) => $price->explanation['list_id'] ?? null)->filter()->unique()->values()->all();

        return view('livewire.pricing.customer-prices', [
            'customer' => $customer,
            'articles' => $articles,
            'resolved' => $resolved,
            'listCodes' => $listIds === [] ? collect() : PriceList::query()->whereIn('id', $listIds)->pluck('code', 'id'),
            'groups' => CustomerPriceGroup::query()->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $customer->price_group_id))->orderBy('name')->get(['id', 'name']),
            'lists' => PriceList::query()->where('kind', PriceListKind::Standard->value)->where(fn ($query) => $query->where('currency', $customer->currency)->orWhere('id', $customer->price_list_id))->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $customer->price_list_id))->orderBy('code')->get(['id', 'code', 'name', 'currency']),
            'canManage' => $this->user()->can('create', [PriceList::class, $this->company()]),
            'currency' => $context->currency,
            // The customer's price agreements that apply now or are on their way (ADR-082 P5).
            'agreements' => PriceAgreement::query()
                ->where('customer_id', $customer->id)
                ->where(fn ($query) => $query
                    ->whereIn('status', [PriceAgreementStatus::Draft->value, PriceAgreementStatus::AwaitingSignature->value, PriceAgreementStatus::Active->value])
                    ->orWhere(fn ($ending) => $ending->where('status', PriceAgreementStatus::Terminated->value)->where('terminated_on', '>=', CarbonImmutable::today()->toDateString())))
                ->orderBy('valid_from')
                ->orderBy('number')
                ->get(),
            'canManageAgreements' => $this->user()->can('create', [PriceAgreement::class, $this->company()]),
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->findOrFail($this->customerId);
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
