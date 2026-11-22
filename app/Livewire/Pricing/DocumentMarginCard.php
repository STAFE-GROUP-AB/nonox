<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Domain\Pricing\DocumentMargin;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceFloorDocument;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The contribution (täckningsbidrag) and contribution margin (täckningsgrad)
 * of a saved invoice, quote or order, for people with prices:view-cost
 * (ADR-082 §9, P7). Internal: it is not on the PDF, in the portal or in the
 * API. Renders nothing for anyone else, and for a document in another
 * currency than kronor.
 */
class DocumentMarginCard extends Component
{
    #[Locked]
    public string $type = 'INVOICE';

    #[Locked]
    public string $documentId = '';

    public function mount(string $type, string $documentId): void
    {
        $this->type = (PriceFloorDocument::tryFrom($type) ?? abort(404))->value;
        $this->documentId = $documentId;

        abort_unless(Gate::allows('view', PriceFloorDocument::from($this->type)->find($documentId)), 403);
    }

    public function render(): View
    {
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        if (! $company instanceof Company || ! $user->hasTeamPermission($company, 'prices:view-cost')) {
            return view('livewire.pricing.document-margin-card', ['margin' => null, 'floor' => null]);
        }

        $document = PriceFloorDocument::from($this->type)->find($this->documentId);
        $inKronor = $document instanceof Invoice ? $document->invoice_currency === 'SEK' : $document->currency === 'SEK';
        $margin = $inKronor && ! ($document instanceof Invoice && $document->isCredit()) ? DocumentMargin::ofDocument($company, $document) : null;

        return view('livewire.pricing.document-margin-card', [
            'margin' => $margin !== null && $margin->netOre > 0 ? $margin : null,
            'floor' => PriceFloor::of($company),
        ]);
    }
}
