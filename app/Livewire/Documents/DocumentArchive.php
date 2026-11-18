<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Actions\Documents\StoreDocument;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentCategory;
use App\Domain\Documents\DocumentSearch;
use App\Domain\Documents\RetentionClass;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Document;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Dokument (ADR-081 gap 9, E19): the company's own documents with search,
 * filters kept in the address, and the upload form. A fixed number of
 * queries whatever the number of documents.
 */
class DocumentArchive extends Component
{
    use ShowsToasts;
    use WithFileUploads;
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'kategori')]
    public string $category = '';

    #[Url(as: 'klass')]
    public string $retention = '';

    public bool $showForm = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?TemporaryUploadedFile $upload = null;

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('viewAny', [Document::class, $this->company()]), 403);

        $this->form = $this->freshForm();
    }

    /** @return array<string, mixed> the empty form, with a class the user may file in */
    private function freshForm(): array
    {
        $classes = DocumentAccess::classes($this->user(), $this->company(), 'documents:manage');
        $form = self::emptyForm();

        if ($classes !== [] && ! in_array(RetentionClass::Other, $classes, true)) {
            $form['retention_class'] = $classes[0]->value;
        }

        return $form;
    }

    /** @return array<string, mixed> */
    public static function emptyForm(): array
    {
        return [
            'title' => '',
            'category' => DocumentCategory::Agreement->value,
            'retention_class' => RetentionClass::Other->value,
            'fiscal_year_id' => '',
            'contract_ends_on' => '',
            'contract_retention_years' => '2',
            'is_accounting_record' => false,
            'keep_until' => '',
            'contains_personal_data' => '',
            'tags' => '',
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategory(): void
    {
        $this->resetPage();
    }

    public function updatingRetention(): void
    {
        $this->resetPage();
    }

    public function save(StoreDocument $store): void
    {
        $document = $store($this->user(), $this->company(), $this->form, $this->upload);

        $this->form = $this->freshForm();
        $this->upload = null;
        $this->showForm = false;
        $this->toast(__('Dokumentet :title är sparat.', ['title' => $document->title]));
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();

        $query = Document::query()
            ->visibleTo($user, $company)
            ->with(['currentVersion:id,document_id,version,original_name,mime,size', 'tags:id,document_id,tag'])
            ->withCount(['links', 'versions']);

        if ($this->category !== '') {
            $query->where('category', $this->category);
        }

        if (RetentionClass::tryFrom($this->retention) !== null) {
            $query->where('retention_class', $this->retention);
        }

        $documents = DocumentSearch::apply($query, $this->search)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $own = Document::query()
            ->visibleTo($user, $company)
            ->whereNotIn('category', array_column(DocumentCategory::cases(), 'value'))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return view('livewire.documents.archive', [
            'documents' => $documents,
            'categories' => [
                ...array_map(fn (DocumentCategory $category): array => ['value' => $category->value, 'label' => $category->label()], DocumentCategory::cases()),
                ...array_map(fn (string $category): array => ['value' => $category, 'label' => $category], $own),
            ],
            'classes' => DocumentAccess::classes($user, $company, 'documents:view'),
            'manageClasses' => DocumentAccess::classes($user, $company, 'documents:manage'),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'company_id', 'start_date', 'end_date']),
            'canManage' => Gate::forUser($user)->allows('create', [Document::class, $company]),
            'filtered' => $this->search !== '' || $this->category !== '' || $this->retention !== '',
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function company(): Company
    {
        $company = $this->user()->currentCompany();
        abort_unless($company instanceof Company, 403);

        return $company;
    }
}
