<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\EditIntercompanyTags;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\RefreshIntercompanyTags;
use App\Actions\Group\TagIntercompanyRows;
use App\Actions\Group\UnmarkIntercompanyCounterparty;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanySide;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\Customer;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyRowTag;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\IntercompanyPolicy;
use App\Support\Money\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bokföring → Koncernmarkeringar (ADR-060, addendum 2026-11-07, 2.3; ADR-081
 * E23): the company's own customers and suppliers marked as group
 * companies, "Uppdatera koncernmarkeringar" with a preview, bulk tagging of
 * the rows on an account, and the tagged rows. Thin: every write goes
 * through an Action. Reads only this company's data, in a fixed number of
 * queries.
 */
class IntercompanyPage extends Component
{
    use ShowsToasts;
    use WithPagination;

    public string $markSide = 'customer';

    public string $markSearch = '';

    public ?int $markPartyId = null;

    public string $markOrgNumber = '';

    /** @var ?array{added: int, changed: int, removed: int, kept: int, in_closed_year: int, in_locked_period: int} */
    public ?array $refreshPlan = null;

    public string $tagAccount = '';

    public string $tagFrom = '';

    public string $tagTo = '';

    public string $tagOrgNumber = '';

    public string $tagKind = '';

    /** @var ?array{rows: int, tagged: int, debit_ore: int, credit_ore: int, in_closed_year: int, in_locked_period: int, warning: ?string} */
    public ?array $tagPreview = null;

    #[Url(as: 'motpart')]
    public string $filterOrg = '';

    #[Url(as: 'typ')]
    public string $filterKind = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [IntercompanyRowTag::class, $this->company()]), 403);

        $this->tagFrom = now()->startOfYear()->toDateString();
        $this->tagTo = now()->toDateString();
    }

    public function updatedMarkSide(): void
    {
        $this->reset('markSearch', 'markPartyId', 'markOrgNumber');
    }

    public function updatedMarkSearch(): void
    {
        $this->markPartyId = null;
    }

    public function choose(int $partyId): void
    {
        $party = $this->side() === IntercompanySide::Customer
            ? Customer::query()->findOrFail($partyId)
            : Supplier::query()->findOrFail($partyId);

        $this->markPartyId = $party->id;
        $this->markSearch = $party->name;
        $this->markOrgNumber = (string) $party->org_number;
    }

    public function suggest(string $side, int $partyId): void
    {
        $this->markSide = IntercompanySide::from($side)->value;
        $this->choose($partyId);
    }

    public function mark(MarkIntercompanyCounterparty $mark): void
    {
        $this->resetErrorBag();

        if ($this->markPartyId === null) {
            $this->addError('markPartyId', $this->side() === IntercompanySide::Customer ? __('Välj en kund.') : __('Välj en leverantör.'));

            return;
        }

        $marking = self::renamingErrors(['counterparty_org_number' => 'markOrgNumber'], fn () => $mark($this->user(), $this->company(), $this->side(), $this->markPartyId, $this->markOrgNumber));
        $name = $this->markSearch;

        $this->reset('markSearch', 'markPartyId', 'markOrgNumber', 'refreshPlan');
        $this->toast(__(':name är markerat som koncernföretag med organisationsnummer :number. Välj Uppdatera koncernmarkeringar för att markera raderna.', ['name' => $name, 'number' => $marking->counterparty_org_number]));
    }

    public function unmark(int $markingId, UnmarkIntercompanyCounterparty $unmark): void
    {
        $unmark($this->user(), $this->company(), $markingId);

        $this->reset('refreshPlan');
        $this->toast(__('Markeringen är borttagen. Raderna den gav tas bort när du uppdaterar koncernmarkeringarna.'));
    }

    public function previewRefresh(RefreshIntercompanyTags $refresh): void
    {
        $plan = $refresh->plan($this->user(), $this->company());
        $this->refreshPlan = [...$plan->counts(), ...$plan->flags()];
    }

    public function applyRefresh(RefreshIntercompanyTags $refresh): void
    {
        $expected = $this->refreshPlan;
        $counts = $refresh($this->user(), $this->company());

        $this->reset('refreshPlan');
        $this->resetPage();

        if ($expected !== null && array_intersect_key($expected, $counts) != $counts) {
            $this->toast(__('Koncernmarkeringarna är uppdaterade, men utfallet skiljer sig från det som visades eftersom bokföringen eller markeringarna ändrades under tiden. Nya: :added, ändrade: :changed, borttagna: :removed.', $counts), 'warning');

            return;
        }

        $this->toast(__('Koncernmarkeringarna är uppdaterade. Nya: :added, ändrade: :changed, borttagna: :removed.', $counts));
    }

    public function cancelRefresh(): void
    {
        $this->reset('refreshPlan');
    }

    public function updatedTagAccount(TagIntercompanyRows $tag): void
    {
        $this->tagPreview = null;
        $number = (int) $this->tagAccount;

        if ($number < 1000 || $number > 9999) {
            return;
        }

        $default = $tag->defaultKind($this->user(), $this->company(), $number);

        if ($default['kind'] !== null) {
            $this->tagKind = $default['kind']->value;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tagFrom', 'tagTo', 'tagOrgNumber', 'tagKind'], true)) {
            $this->tagPreview = null;
        }

        if (in_array($property, ['filterOrg', 'filterKind'], true)) {
            $this->resetPage();
        }
    }

    public function previewTagging(TagIntercompanyRows $tag): void
    {
        $this->resetErrorBag();
        $this->tagPreview = $tag->preview($this->user(), $this->company(), $this->selection());
    }

    public function applyTagging(TagIntercompanyRows $tag): void
    {
        $this->resetErrorBag();

        $result = self::renamingErrors(['counterparty_org_number' => 'tagOrgNumber'], fn (): array => $tag($this->user(), $this->company(), [
            ...$this->selection(),
            'counterparty_org_number' => $this->tagOrgNumber,
            'kind' => $this->tagKind,
        ]));

        $this->tagPreview = null;
        $this->resetPage();
        $this->toast($result['skipped'] > 0
            ? __('Klart. Markerade rader: :tagged. Rader som redan hade en markering och inte ändrades: :skipped.', $result)
            : __('Klart. Markerade rader: :tagged.', $result));
    }

    public function removeTagging(EditIntercompanyTags $edit): void
    {
        $this->resetErrorBag();

        $removed = $edit->removeSelected($this->user(), $this->company(), $this->selection());

        $this->tagPreview = null;
        $this->resetPage();
        $this->toast(__('Borttagna markeringar i urvalet: :count.', ['count' => $removed]));
    }

    public function changeTag(int $tagId, string $kind, EditIntercompanyTags $edit): void
    {
        validator(['kind' => $kind], ['kind' => ['required', Rule::enum(IntercompanyKind::class)]])->validate();

        $edit->change($this->user(), $this->company(), $tagId, IntercompanyKind::from($kind));

        $this->toast(__('Raden är nu markerad som :kind.', ['kind' => mb_strtolower(IntercompanyKind::from($kind)->label())]));
    }

    public function removeTag(int $tagId, EditIntercompanyTags $edit): void
    {
        $edit->remove($this->user(), $this->company(), $tagId);

        $this->toast(__('Markeringen är borttagen från raden. Verifikationen är oförändrad.'));
    }

    public function render(): View
    {
        $counterparties = IntercompanyCounterparty::query()->with(['customer:id,name,number', 'supplier:id,name,number'])->orderBy('counterparty_org_number')->orderBy('id')->get();
        $holdings = CompanyHolding::query()->orderBy('held_name')->get(['id', 'held_org_number', 'held_name']);
        $names = $this->names($counterparties, $holdings);

        return view('livewire.group.intercompany-page', [
            'canWrite' => IntercompanyPolicy::mayWrite($this->user(), $this->company()),
            'counterparties' => $counterparties,
            'suggestions' => $this->suggestions($counterparties, $holdings),
            'matches' => $this->matches(),
            'summary' => $this->summary(),
            'tags' => $this->tags(),
            'names' => $names,
            'kinds' => IntercompanyKind::cases(),
        ]);
    }

    /**
     * Customers or suppliers matching the search, for the marking form; one query.
     *
     * @return Collection<int, Customer>|Collection<int, Supplier>
     */
    private function matches(): Collection
    {
        $term = trim($this->markSearch);

        if (mb_strlen($term) < 2 || $this->markPartyId !== null) {
            return collect();
        }

        $model = $this->side() === IntercompanySide::Customer ? Customer::class : Supplier::class;

        return $model::query()
            ->where(fn (Builder $query) => $query->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhere('org_number', 'like', addcslashes($term, '%_\\').'%')->orWhereRaw('number::text = ?', [$term]))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'number', 'name', 'org_number']);
    }

    /**
     * Customers and suppliers whose organisation number is a company this
     * company holds shares in, not yet marked: an aid, a person still marks.
     * Two queries.
     *
     * @param  Collection<int, IntercompanyCounterparty>  $counterparties
     * @param  Collection<int, CompanyHolding>  $holdings
     * @return list<array{side: IntercompanySide, id: int, name: string, org: string}>
     */
    private function suggestions(Collection $counterparties, Collection $holdings): array
    {
        $numbers = $holdings->pluck('held_org_number')->unique()->values()->all();

        if ($numbers === []) {
            return [];
        }

        $suggestions = [];

        foreach ([[IntercompanySide::Customer, Customer::class, 'customer_id'], [IntercompanySide::Supplier, Supplier::class, 'supplier_id']] as [$side, $model, $column]) {
            $marked = $counterparties->pluck($column)->filter()->all();

            foreach ($model::query()->whereIn('org_number', $numbers)->whereNotIn('id', $marked)->orderBy('name')->get(['id', 'name', 'org_number']) as $party) {
                $suggestions[] = ['side' => $side, 'id' => $party->id, 'name' => $party->name, 'org' => (string) $party->org_number];
            }
        }

        return $suggestions;
    }

    /**
     * Tagged rows per group company and kind, with the debit and credit
     * sums; one query.
     *
     * @return Collection<int, object{org: string, kind: string, rows: int, debit_ore: int, credit_ore: int}>
     */
    private function summary(): Collection
    {
        /** @var Collection<int, object{org: string, kind: string, rows: int, debit_ore: int, credit_ore: int}> */
        return IntercompanyRowTag::query()
            ->join('voucher_rows', 'voucher_rows.id', '=', 'intercompany_row_tags.voucher_row_id')
            ->where('voucher_rows.company_id', $this->company()->id)
            ->groupBy('intercompany_row_tags.counterparty_org_number', 'intercompany_row_tags.kind')
            ->orderBy('intercompany_row_tags.counterparty_org_number')
            ->orderBy('intercompany_row_tags.kind')
            ->toBase()
            ->selectRaw('intercompany_row_tags.counterparty_org_number AS org, intercompany_row_tags.kind, COUNT(*) AS rows, SUM(voucher_rows.debit_ore) AS debit_ore, SUM(voucher_rows.credit_ore) AS credit_ore')
            ->get()
            ->map(fn (object $row): object => (object) ['org' => (string) $row->org, 'kind' => (string) $row->kind, 'rows' => (int) $row->rows, 'debit_ore' => (int) $row->debit_ore, 'credit_ore' => (int) $row->credit_ore]);
    }

    /**
     * The tagged rows, newest first, filtered from the address; a page of 25
     * with its rows, accounts, vouchers and series loaded up front.
     */
    /** @return LengthAwarePaginator<int, IntercompanyRowTag> */
    private function tags(): LengthAwarePaginator
    {
        return IntercompanyRowTag::query()
            ->with(['row:id,voucher_id,account_id,debit_ore,credit_ore,description', 'row.account:id,number,name', 'row.voucher:id,series_id,number,voucher_date,description', 'row.voucher.series:id,code'])
            ->when($this->filterOrg !== '', fn (Builder $query) => $query->where('counterparty_org_number', $this->filterOrg))
            ->when(IntercompanyKind::tryFrom($this->filterKind) !== null, fn (Builder $query) => $query->where('kind', $this->filterKind))
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * A name per organisation number from the company's own markings and
     * holdings, never from another company's data.
     *
     * @param  Collection<int, IntercompanyCounterparty>  $counterparties
     * @param  Collection<int, CompanyHolding>  $holdings
     * @return array<string, string>
     */
    private function names(Collection $counterparties, Collection $holdings): array
    {
        $names = [];

        foreach ($holdings as $holding) {
            $names[$holding->held_org_number] = $holding->held_name;
        }

        foreach ($counterparties as $counterparty) {
            $names[$counterparty->counterparty_org_number] ??= $counterparty->partyName();
        }

        return $names;
    }

    /** @return array{account: string, from: string, to: string} */
    private function selection(): array
    {
        return ['account' => trim($this->tagAccount), 'from' => $this->tagFrom, 'to' => $this->tagTo];
    }

    /**
     * Runs an action and moves its errors to the form's own field names, so
     * the two organisation number fields on the page never share an error.
     *
     * @template T
     *
     * @param  array<string, string>  $names
     * @param  callable(): T  $action
     * @return T
     */
    private static function renamingErrors(array $names, callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[$names[$key] ?? $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function side(): IntercompanySide
    {
        return IntercompanySide::tryFrom($this->markSide) ?? IntercompanySide::Customer;
    }

    public static function money(int $ore): string
    {
        return Money::ofOre($ore)->format();
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
