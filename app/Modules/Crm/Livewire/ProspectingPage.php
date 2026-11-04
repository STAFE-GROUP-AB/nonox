<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\RegistryCompany;
use App\Models\User;
use App\Modules\Crm\Actions\AddProspects;
use App\Modules\Crm\Actions\CreateCallList;
use App\Modules\Crm\Domain\LookAlike;
use App\Modules\Crm\Domain\ProspectSearch;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\CompanyRegistry\RegistryCodes;
use App\Support\CompanyRegistry\Sni;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Prospektera (ADR-047): search the instance's company register with
 * filters, or find companies like the best customers (look-alike, no AI),
 * and add the hits as prospects, one, the ticked ones or all (at most 500),
 * optionally as a call list for a seller. A name or organisation number
 * search works alone or with the filters; 25 to 200 hits per page. The
 * search, the filters and the page size live in the query string. A fixed number of queries per page.
 */
class ProspectingPage extends Component
{
    use ShowsToasts;
    use WithPagination;

    /** Hits are counted up to this many; more shows as "fler än 10 000". */
    public const int COUNT_CAP = 10_000;

    /** The page sizes a user can choose; anything else shows the first. */
    public const array PER_PAGE = [25, 50, 100, 200];

    public const array FILTERS = ['search', 'division', 'sni', 'town', 'postal', 'county', 'municipality', 'form', 'from', 'to', 'employeesMin', 'employeesMax', 'turnoverMin', 'turnoverMax', 'all', 'blocked'];

    #[Url(as: 'lage', except: 'sok')]
    public string $mode = 'sok';

    /** Name contains, or organisation number starts with (ProspectSearch::matching). */
    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'per-sida', except: '25')]
    public string $perPage = '25';

    #[Url(as: 'huvudgrupp', except: '')]
    public string $division = '';

    #[Url(as: 'sni', except: '')]
    public string $sni = '';

    #[Url(as: 'ort', except: '')]
    public string $town = '';

    #[Url(as: 'postnummer', except: '')]
    public string $postal = '';

    #[Url(as: 'lan', except: '')]
    public string $county = '';

    #[Url(as: 'kommun', except: '')]
    public string $municipality = '';

    #[Url(as: 'form', except: '')]
    public string $form = '';

    #[Url(as: 'fran', except: '')]
    public string $from = '';

    #[Url(as: 'till', except: '')]
    public string $to = '';

    #[Url(as: 'anstallda-fran', except: '')]
    public string $employeesMin = '';

    #[Url(as: 'anstallda-till', except: '')]
    public string $employeesMax = '';

    #[Url(as: 'omsattning-fran', except: '')]
    public string $turnoverMin = '';

    #[Url(as: 'omsattning-till', except: '')]
    public string $turnoverMax = '';

    /** Include companies that are not in business (inactive, deregistered, bankrupt). */
    #[Url(as: 'alla', except: false)]
    public bool $all = false;

    /** Include companies that have opted out of postal advertising. */
    #[Url(as: 'reklamsparr', except: false)]
    public bool $blocked = false;

    /** @var list<int|string> look-alike: the chosen best customers (account ids); empty means the first five */
    public array $chosen = [];

    #[Url(as: 'bransch', except: 'code')]
    public string $likeSni = LookAlike::SNI_CODE;

    #[Url(as: 'storlek', except: true)]
    public bool $likeSize = true;

    #[Url(as: 'region', except: true)]
    public bool $likeRegion = true;

    /** @var list<string> ticked organisation numbers */
    public array $selected = [];

    public string $ownerId = '';

    public bool $asCallList = false;

    public string $listName = '';

    public string $listAssignee = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Account::class, $this->company()]), 403);

        if (! in_array($this->mode, ['sok', 'liknande'], true)) {
            $this->mode = 'sok';
        }

        $this->perPage = (string) $this->pageSize();
        $this->ownerId = (string) $this->user()->id;
        $this->listAssignee = (string) $this->user()->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, [...self::FILTERS, 'mode', 'likeSni', 'likeSize', 'likeRegion'], true) || str_starts_with($property, 'chosen')) {
            $this->resetPage();
            $this->selected = [];
        }

        if ($property === 'perPage') {
            $this->perPage = (string) $this->pageSize();
            $this->resetPage();
        }

        if ($property === 'division') {
            $this->sni = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
        $this->selected = [];
    }

    public function addOne(string $orgNumber, AddProspects $add): void
    {
        $result = $add($this->user(), $this->company(), [$orgNumber], $this->owner());
        $id = $result['added'][0] ?? $result['existing'][0] ?? null;

        if ($id === null && $result['missing'] > 0) {
            $this->toast(__('Företaget finns inte längre i registret.'), 'error');

            return;
        }

        if ($id === null) {
            $this->toast(__('Företaget lades inte till: dess telefonnummer eller e-post finns på listan Kontakta inte.'), 'error');

            return;
        }

        $name = Account::query()->whereKey($id)->value('name');
        $this->toast($result['added'] === [] ? __(':name finns redan bland kunderna.', ['name' => $name]) : __(':name är tillagt som prospekt.', ['name' => $name]));
    }

    public function addSelected(AddProspects $add, CreateCallList $create): void
    {
        $this->addMany($this->selected, $add, $create);
    }

    public function addAll(AddProspects $add, CreateCallList $create): void
    {
        $org = array_values($this->results()->orderBy('name')->orderBy('org_number')->limit(ProspectSearch::MAX_ADD)->pluck('org_number')->map(fn (mixed $number): string => (string) $number)->all());

        $this->addMany($org, $add, $create);
    }

    public function render(LookAlike $lookAlike): View
    {
        $company = $this->company();
        $state = ProspectSearch::state();
        $best = collect();
        $profiles = collect();
        $names = [];

        if ($this->mode === 'liknande') {
            $best = $lookAlike->bestCustomers();

            // The first five are chosen until the user picks: tick them, so the boxes show what is compared.
            if ($this->chosen === []) {
                $this->chosen = array_values(array_map(fn (object $row): string => (string) $row->account_id, $best->take(LookAlike::DEFAULT_CHOSEN)->all()));
            }

            $chosen = $this->chosenAccounts($best);
            $names = $chosen->mapWithKeys(fn (object $row): array => [(string) RegistryCompany::digits($row->org_number) => $row->name])->all();
            $profiles = $lookAlike->profiles($this->orgNumbers($chosen));
        }

        $searching = $this->mode === 'liknande' ? $profiles->isNotEmpty() : ProspectSearch::hasFilter($this->filters());
        $query = $searching ? $this->results($profiles) : null;
        // A capped count and a simple page: counting a million rows on every change of a filter is not needed.
        $total = $query === null ? 0 : DB::query()->fromSub((clone $query)->select('company_registry.id')->limit(self::COUNT_CAP + 1), 'hits')->count();
        $results = $query === null ? null : $query->orderBy('name')->orderBy('org_number')->simplePaginate($this->pageSize());

        return view('crm::livewire.prospecting', [
            'state' => $state,
            'results' => $results,
            'total' => $total,
            'capped' => $total > self::COUNT_CAP,
            'searching' => $searching,
            'best' => $best,
            'chosenIds' => $this->mode === 'liknande' ? $this->chosenAccounts($best)->pluck('account_id')->all() : [],
            'profiles' => $profiles,
            'explain' => fn (RegistryCompany $hit): ?array => $this->mode === 'liknande' ? $lookAlike->explain($hit, $profiles, $this->criteria(), $names) : null,
            'sections' => Sni::sections(),
            'divisions' => Sni::divisions(),
            'codes' => $this->division === '' ? [] : array_filter(Sni::codes(), fn (string $name, int|string $code): bool => str_starts_with((string) $code, $this->division), ARRAY_FILTER_USE_BOTH),
            'forms' => RegistryCodes::LEGAL_FORMS,
            'counties' => RegistryCodes::COUNTIES,
            'municipalities' => $state['api'] ? RegistryCodes::table('municipality') : [],
            'employeeClasses' => RegistryCodes::EMPLOYEE_CLASSES,
            'turnoverClasses' => RegistryCodes::TURNOVER_CLASSES,
            'members' => CompanyMembers::all($company),
            'canManage' => $this->user()->can('manage', [Account::class, $company]),
            'filtered' => ProspectSearch::hasFilter($this->filters()) || $this->all || $this->blocked,
            'pageSizes' => self::PER_PAGE,
            'shortSearch' => trim($this->search) !== '' && ProspectSearch::searchTerm($this->search) === null,
        ]);
    }

    /**
     * @param  list<string>  $orgNumbers
     */
    private function addMany(array $orgNumbers, AddProspects $add, CreateCallList $create): void
    {
        $result = $add($this->user(), $this->company(), $orgNumbers, $this->owner());
        $ids = [...$result['added'], ...$result['existing']];
        $message = trans_choice('{0} Inga nya prospekt.|{1} 1 nytt prospekt.|[2,*] :count nya prospekt.', count($result['added']), ['count' => count($result['added'])]);

        if ($result['existing'] !== []) {
            $message .= ' '.trans_choice('{1} 1 fanns redan.|[2,*] :count fanns redan.', count($result['existing']), ['count' => count($result['existing'])]);
        }

        if ($result['suppressed'] > 0) {
            $message .= ' '.trans_choice('{1} 1 lades inte till eftersom det finns på listan Kontakta inte.|[2,*] :count lades inte till eftersom de finns på listan Kontakta inte.', $result['suppressed'], ['count' => $result['suppressed']]);
        }

        $this->selected = [];

        if ($this->asCallList && $ids !== []) {
            $made = $create($this->user(), $this->company(), [
                'name' => $this->listName !== '' ? $this->listName : __('Ringlista :date', ['date' => now()->translatedFormat('j M Y')]),
                'assigned_to' => $this->listAssignee === '' ? null : (int) $this->listAssignee,
            ], $ids, ['source' => $this->mode === 'liknande' ? 'lookalike' : 'register', 'filters' => array_filter($this->filters(), fn (mixed $value): bool => $value !== '' && $value !== false)]);

            Toast::flash($message.' '.__('Ringlistan är skapad.'));
            $this->redirectRoute('crm.call-lists.show', ['callList' => $made['list']->id]);

            return;
        }

        $this->toast($message);
    }

    /**
     * The hits of the current search or look-alike.
     *
     * @param  Collection<string, RegistryCompany>|null  $profiles
     * @return Builder<RegistryCompany>
     */
    private function results(?Collection $profiles = null): Builder
    {
        if ($this->mode === 'liknande') {
            $lookAlike = app(LookAlike::class);

            if ($profiles === null) {
                $chosen = $this->chosenAccounts($lookAlike->bestCustomers());
                $profiles = $lookAlike->profiles($this->orgNumbers($chosen));
            }

            $query = $lookAlike->query($profiles, $this->criteria(), $this->company());
            ProspectSearch::matching($query, $this->search);

            return $query;
        }

        return ProspectSearch::query($this->filters(), $this->company());
    }

    /**
     * @param  Collection<int, object{account_id: int, name: string, org_number: ?string, invoiced: int}>  $best
     * @return Collection<int, object{account_id: int, name: string, org_number: ?string, invoiced: int}>
     */
    private function chosenAccounts(Collection $best): Collection
    {
        $chosen = array_map('intval', $this->chosen);

        return $chosen === []
            ? $best->take(LookAlike::DEFAULT_CHOSEN)->values()
            : $best->filter(fn (object $row): bool => in_array($row->account_id, $chosen, true))->values();
    }

    /**
     * @param  Collection<int, object{account_id: int, name: string, org_number: ?string, invoiced: int}>  $customers
     * @return list<string>
     */
    private function orgNumbers(Collection $customers): array
    {
        return array_values($customers->map(fn (object $row): string => (string) $row->org_number)->filter(fn (string $number): bool => $number !== '')->all());
    }

    /** @return array{sni: string, size: bool, region: bool, blocked: bool} */
    private function criteria(): array
    {
        return [
            'sni' => in_array($this->likeSni, [LookAlike::SNI_CODE, LookAlike::SNI_DIVISION, ''], true) ? $this->likeSni : LookAlike::SNI_CODE,
            'size' => $this->likeSize,
            'region' => $this->likeRegion,
            'blocked' => $this->blocked,
        ];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'search' => $this->search,
            'sni' => $this->sni !== '' ? $this->sni : $this->division,
            'town' => $this->town,
            'postal' => $this->postal,
            'county' => $this->county,
            'municipality' => $this->municipality,
            'form' => $this->form,
            'from' => $this->from,
            'to' => $this->to,
            'employees_min' => $this->employeesMin,
            'employees_max' => $this->employeesMax,
            'turnover_min' => $this->turnoverMin,
            'turnover_max' => $this->turnoverMax,
            'all' => $this->all,
            'blocked' => $this->blocked,
            'new' => true,
        ];
    }

    /** The chosen page size, or the first allowed one for anything else (a hand-edited URL). */
    private function pageSize(): int
    {
        $size = (int) $this->perPage;

        return in_array($size, self::PER_PAGE, true) ? $size : self::PER_PAGE[0];
    }

    private function owner(): ?int
    {
        return $this->ownerId === '' ? null : (int) $this->ownerId;
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
