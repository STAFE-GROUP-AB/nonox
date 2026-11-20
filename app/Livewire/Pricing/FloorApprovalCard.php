<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorEligibility;
use App\Domain\Pricing\PriceFloorGuard;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The approval of a document's manual prices under the company's lowest
 * contribution margin (ADR-082 §9, P7), shown on the invoice draft, quote or
 * order only while the company requires approval and a price needs it. It
 * follows the version of the rows last saved: a changed row is a new version
 * that is asked for and approved again.
 */
class FloorApprovalCard extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $type = 'INVOICE';

    #[Locked]
    public string $documentId = '';

    public string $reason = '';

    public function mount(string $type, string $documentId): void
    {
        $this->type = (PriceFloorDocument::tryFrom($type) ?? abort(404))->value;
        $this->documentId = $documentId;

        abort_unless(Gate::allows('view', $this->document()), 403);
    }

    /** The editor saved the document: the rows may have changed. */
    #[On('price-floor-recheck')]
    public function recheck(): void
    {
        $this->reset('reason');
    }

    public function askForApproval(RequestPriceFloorApproval $request): void
    {
        try {
            $request($this->user(), $this->company(), $this->kind(), $this->documentId);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (AuthorizationException) {
            $this->toast(__('Du har inte behörighet att begära godkännande för :what.', ['what' => $this->kind()->label()]), 'error');

            return;
        }

        $this->toast(__('Godkännande är begärt. Någon med behörighet får se begäran på :what.', ['what' => $this->kind()->label()]));
    }

    public function approve(DecidePriceFloorApproval $decide, int $approvalId): void
    {
        $this->decide($decide, $approvalId, true);
    }

    public function reject(DecidePriceFloorApproval $decide, int $approvalId): void
    {
        $this->decide($decide, $approvalId, false);
    }

    private function decide(DecidePriceFloorApproval $decide, int $approvalId, bool $approve): void
    {
        $this->resetErrorBag();

        try {
            $decide($this->user(), $this->company(), $approvalId, $approve, $this->reason);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (AuthorizationException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->reset('reason');
        $this->toast($approve ? __('Priserna är godkända.') : __('Priserna är avslagna.'));
    }

    public function render(): View
    {
        $document = $this->document();
        $company = $this->company();
        $state = PriceFloorGuard::state($company, $document);

        return view('livewire.pricing.floor-approval-card', [
            'state' => $state,
            'what' => $this->kind()->label(),
            'canRequest' => $state->needed && ! $state->pending() && ! $state->approved() && Gate::allows('update', $document),
            'refusal' => $state->pending() && $state->approval !== null ? PriceFloorEligibility::refusal($this->user(), $company, $document, $state->approval) : null,
            'requester' => $state->approval?->requester()->first(['id', 'name']),
            'decider' => $state->approval?->decider()->first(['id', 'name']),
        ]);
    }

    private function kind(): PriceFloorDocument
    {
        return PriceFloorDocument::from($this->type);
    }

    private function document(): Invoice|SalesDocument
    {
        return $this->kind()->find($this->documentId);
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
