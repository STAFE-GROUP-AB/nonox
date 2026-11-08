<?php

declare(strict_types=1);

namespace App\Livewire\Signing;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * "Skicka för signering" on a quote or an agreement (ADR-046, core since
 * ADR-075 §7): the form (address, last day, a CRM deal to win while CRM is
 * on, an invoice draft too), and every link sent for the document with its
 * status, its trail of events, the signed documents and Återkalla for an
 * open one. A quote's panel works whether or not CRM is on.
 */
class SigningPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'signing.panel';

    #[Locked]
    public ?string $quoteId = null;

    #[Locked]
    public ?int $agreementId = null;

    public string $email = '';

    public string $expiresOn = '';

    public string $dealId = '';

    public bool $createInvoice = false;

    public function mount(?string $quoteId = null, ?int $agreementId = null): void
    {
        $this->quoteId = $quoteId;
        $this->agreementId = $agreementId;
        $document = $this->document();
        abort_unless(Gate::allows('view', $document), 403);

        $this->email = (string) ($document instanceof SalesDocument
            ? ($document->sent_to ?? Customer::query()->whereKey($document->customer_id)->value('email'))
            : Account::query()->whereKey($document->account_id)->value('email'));
        $deals = $this->openDeals($document);
        $this->dealId = $deals->count() === 1 ? (string) $deals->first()?->id : '';
    }

    public function send(SendForSigning $send): void
    {
        $this->resetErrorBag();

        $send($this->user(), $this->company(), $this->document(), [
            'email' => $this->email,
            'expires_on' => $this->expiresOn === '' ? null : $this->expiresOn,
            'deal_id' => $this->dealId === '' ? null : (int) $this->dealId,
            'create_invoice' => $this->createInvoice,
        ]);

        $this->toast(__('Länken för signering är skickad till :email.', ['email' => $this->email]));
    }

    public function revoke(int $requestId, SendForSigning $send): void
    {
        $request = $this->requests()->whereKey($requestId)->firstOrFail();
        $send->revoke($this->user(), $this->company(), $request);

        $this->toast(__('Länken är återkallad. Den går inte längre att använda.'));
    }

    public function render(): View
    {
        $document = $this->document();
        $user = $this->user();
        $isQuote = $document instanceof SalesDocument;

        return view('livewire.signing.panel', [
            'isQuote' => $isQuote,
            'crm' => app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company()),
            'requests' => $this->requests()->with(['events', 'sender:id,name'])->latest('id')->limit(10)->get(),
            'canSend' => $this->canSend($document),
            'deals' => $this->openDeals($document),
            'open' => $document->signingIsOpen() && ! ($isQuote && SignedDocuments::isQuoteSigned($document->id)),
            'canInvoice' => $isQuote && $user->hasTeamPermission($this->company(), 'invoices:create'),
            'now' => now(),
        ]);
    }

    private function document(): SalesDocument|Agreement
    {
        $document = $this->quoteId !== null
            ? SalesDocument::query()->where('kind', SalesDocument::QUOTE)->findOrFail($this->quoteId)
            : Agreement::query()->findOrFail((int) $this->agreementId);

        abort_unless(SendForSigning::moduleOn($document, $this->company()), 404);

        return $document;
    }

    /** @return Builder<SigningRequest> */
    private function requests(): Builder
    {
        $document = $this->document();

        return SigningRequest::query()
            ->where('document_type', $document->signingType())
            ->where(app(SignableTypes::class)->column($document->signingType()), $document->signingKey());
    }

    /** @return Collection<int, Deal> */
    private function openDeals(SalesDocument|Agreement $document): Collection
    {
        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company())) {
            return collect();
        }

        $accountId = $document instanceof Agreement ? $document->account_id : Account::query()->where('customer_id', $document->customer_id)->value('id');

        if ($accountId === null || ! $this->user()->can('viewAny', [Deal::class, $this->company()])) {
            return collect();
        }

        return Deal::query()->where('account_id', $accountId)->where('status', DealStatus::Open->value)->orderBy('title')->get(['id', 'title']);
    }

    private function canSend(Signable $document): bool
    {
        try {
            $document->authorizeSigning($this->user());
        } catch (Throwable) {
            return false;
        }

        return $document->signingIsOpen();
    }

    public static function statusOf(SigningRequest $request): SigningStatus
    {
        return $request->effectiveStatus();
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
