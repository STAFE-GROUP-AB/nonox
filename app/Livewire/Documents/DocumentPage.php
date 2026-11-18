<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\AttachDocumentToVoucher;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\LinkDocument;
use App\Actions\Documents\SetLegalHold;
use App\Actions\Documents\UpdateDocument;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentCategory;
use App\Domain\Documents\DocumentLinkType;
use App\Domain\Documents\DocumentRetention;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Documents\RetentionClass;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One company document: its details and retention, versions, tags, links,
 * legal hold and deletion (ADR-081 gap 9, E19). A fixed number of queries,
 * however many versions and links the document has.
 */
class DocumentPage extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    /** The most records offered when linking; the rest are found by number. */
    public const int LINK_OPTIONS = 300;

    #[Locked]
    public int $documentId;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?TemporaryUploadedFile $newVersion = null;

    public string $linkType = '';

    public string $linkId = '';

    public string $voucherReference = '';

    public string $holdReason = '';

    public function mount(Document $document): void
    {
        abort_unless(Gate::forUser($this->user())->allows('view', $document), 404);

        $this->documentId = $document->id;
        $this->fillForm($document);
    }

    public function edit(): void
    {
        $this->fillForm($this->document());
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(UpdateDocument $update): void
    {
        $update($this->user(), $this->document(), $this->form);
        $this->editing = false;
        $this->toast(__('Dokumentets uppgifter är sparade.'));
    }

    public function updatedNewVersion(AddDocumentVersion $add): void
    {
        if (! $this->newVersion instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $version = $add($this->user(), $this->document(), $this->newVersion);
        } catch (ValidationException $exception) {
            $this->newVersion = null;
            $this->addError('newVersion', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->newVersion = null;
        $this->toast(__('Version :version är sparad. De tidigare versionerna finns kvar.', ['version' => $version->version]));
    }

    public function addLink(LinkDocument $link): void
    {
        $link($this->user(), $this->document(), $this->linkType, $this->linkId);
        $this->linkId = '';
        $this->toast(__('Kopplingen är sparad.'));
    }

    public function removeLink(int $linkId, LinkDocument $link): void
    {
        $link->remove($this->user(), $this->document(), $linkId);
        $this->toast(__('Kopplingen är borttagen.'));
    }

    public function attachToVoucher(AttachDocumentToVoucher $attach): void
    {
        $attach($this->user(), $this->document(), $this->voucherReference);
        $this->voucherReference = '';
        $this->toast(__('Dokumentet är underlag till verifikationen. Samma fil används, ingen kopia.'));
    }

    public function setHold(bool $hold, SetLegalHold $setHold): void
    {
        $setHold($this->user(), $this->document(), $hold, LegalHoldReason::tryFrom($this->holdReason));
        $this->holdReason = '';
        $this->toast($hold ? __('Bevarandespärren är satt. Dokumentet kan inte raderas förrän den hävs.') : __('Bevarandespärren är hävd.'));
    }

    public function deleteDocument(DeleteDocument $delete): void
    {
        $document = $this->document();
        $delete($this->user(), $document);
        Toast::flash(__('Dokumentet :title är raderat.', ['title' => $document->title]));
        $this->redirectRoute('documents.index', navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $document = Document::query()
            ->with([
                'versions' => fn ($query) => $query->orderByDesc('version')->select(['id', 'company_id', 'document_id', 'version', 'path', 'mime', 'size', 'sha256', 'original_name', 'uploaded_by', 'created_at']),
                'versions.uploader:id,name',
                'tags:id,document_id,tag',
                'links',
                'fiscalYear:id,company_id,start_date,end_date',
            ])
            ->findOrFail($this->documentId);

        $canManage = Gate::forUser($user)->allows('update', $document);
        $linkType = DocumentLinkType::tryFrom($this->linkType);
        $types = DocumentLinkType::availableCases($company, $document->retention_class);

        return view('livewire.documents.show', [
            'document' => $document,
            'linked' => $this->linkedNames($document->links),
            'underlag' => $this->underlag($document),
            'canManage' => $canManage,
            'canHold' => Gate::forUser($user)->allows('hold', $document),
            'holdReasons' => LegalHoldReason::cases(),
            'blocker' => DocumentRetention::blocker($document),
            'linkTypes' => $types,
            'linkOptions' => $canManage && $linkType !== null && in_array($linkType, $types, true) ? $this->options($linkType) : collect(),
            'categories' => DocumentCategory::cases(),
            'classes' => DocumentAccess::classes($user, $company, 'documents:manage'),
            'fiscalYears' => $this->editing ? FiscalYear::query()->orderByDesc('start_date')->get(['id', 'company_id', 'start_date', 'end_date']) : collect(),
            'canAttach' => $document->retention_class === RetentionClass::Accounting && $user->hasTeamPermission($company, 'vouchers:create'),
        ]);
    }

    /**
     * The name of every linked record: one query per type in use, never one
     * per link. A record that no longer exists shows as removed.
     *
     * @param  Collection<int, DocumentLink>  $links
     * @return list<array{id: int, type: DocumentLinkType, name: string}>
     */
    private function linkedNames(Collection $links): array
    {
        $names = [];

        foreach ($links->groupBy(fn (DocumentLink $link): string => $link->linkable_type->value) as $type => $group) {
            $linkType = DocumentLinkType::from((string) $type);
            $class = $linkType->modelClass();
            $records = $class::query()->whereKey($group->pluck('linkable_id')->all())->get($linkType->columns())->keyBy('id');

            foreach ($group as $link) {
                $record = $records->get($link->linkable_id);
                $names[] = ['id' => $link->id, 'type' => $linkType, 'name' => $record === null ? __('Borttagen post') : $linkType->name($record)];
            }
        }

        return $names;
    }

    /**
     * The vouchers a version of the document is underlag for, through
     * voucher_attachments (the same stored file): two queries.
     *
     * @return Collection<int, Voucher>
     */
    private function underlag(Document $document): Collection
    {
        if ($document->retention_class !== RetentionClass::Accounting) {
            return collect();
        }

        $ids = VoucherAttachment::query()->whereIn('file_path', $document->versions->pluck('path')->all())->distinct()->pluck('voucher_id');

        return $ids->isEmpty() ? collect() : Voucher::query()
            ->with('series:id,code')
            ->whereIn('id', $ids->all())
            ->orderBy('voucher_date')
            ->get(['id', 'company_id', 'series_id', 'number', 'status', 'voucher_date']);
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function options(DocumentLinkType $type): Collection
    {
        $class = $type->modelClass();

        return $class::query()
            ->orderBy($type->orderColumn())
            ->limit(self::LINK_OPTIONS)
            ->get($type->columns())
            ->map(fn ($record): array => ['id' => (int) $record->getKey(), 'name' => $type->name($record)]);
    }

    private function fillForm(Document $document): void
    {
        $document->loadMissing('tags:id,document_id,tag');

        $this->form = [
            'title' => $document->title,
            'category' => $document->category,
            'retention_class' => $document->retention_class->value,
            'fiscal_year_id' => (string) $document->fiscal_year_id,
            'contract_ends_on' => (string) $document->contract_ends_on?->toDateString(),
            'contract_retention_years' => (string) ($document->contract_retention_years ?? 2),
            'is_accounting_record' => $document->is_accounting_record,
            'keep_until' => (string) $document->keep_until?->toDateString(),
            'contains_personal_data' => $document->contains_personal_data ? '1' : '0',
            'tags' => $document->tags->pluck('tag')->implode(', '),
        ];
    }

    private function document(): Document
    {
        $document = Document::query()->findOrFail($this->documentId);
        abort_unless(Gate::forUser($this->user())->allows('view', $document), 404);

        return $document;
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
