<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Domain\Documents\DocumentOverview;
use App\Domain\Documents\Sources\DocumentSource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Alla dokument (ADR-081 gap 9, E20): the company's documents and the files
 * of the feature sources in one list. Each source is shown only to a user
 * who may read it (DocumentOverview), with a fixed number of queries.
 */
class DocumentOverviewPage extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'kalla')]
    public string $source = '';

    public function mount(): void
    {
        abort_unless(DocumentOverview::allowed($this->user(), $this->company()) !== [], 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSource(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $sources = DocumentOverview::allowed($user, $company);

        return view('livewire.documents.overview', [
            'documents' => DocumentOverview::page($user, $company, $this->source, $this->search, self::PER_PAGE),
            'sources' => array_map(fn (DocumentSource $source): array => ['key' => $source->key(), 'label' => $source->label()], $sources),
            'filtered' => $this->search !== '' || $this->source !== '',
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
