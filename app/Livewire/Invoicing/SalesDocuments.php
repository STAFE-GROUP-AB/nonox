<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list of quotes or of orders.
 */
class SalesDocuments extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = SalesDocument::QUOTE;

    #[Url(as: 'visa', except: 'oppna')]
    public string $filter = 'oppna';

    public function mount(string $kind): void
    {
        $this->kind = $kind === SalesDocument::ORDER ? SalesDocument::ORDER : SalesDocument::QUOTE;

        abort_unless($this->user()->can('viewAny', [SalesDocument::class, $this->company()]), 403);
    }

    public function render(): View
    {
        return view('livewire.invoicing.sales-documents', [
            'documents' => SalesDocument::query()
                ->where('kind', $this->kind)
                ->when($this->filter === 'oppna', fn ($query) => $query->where('status', SalesDocumentStatus::Open->value))
                ->with('customer:id,name')
                ->orderByDesc('number')
                ->paginate(25),
            'isQuote' => $this->kind === SalesDocument::QUOTE,
            'canCreate' => $this->user()->can('create', [SalesDocument::class, $this->company()]),
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
