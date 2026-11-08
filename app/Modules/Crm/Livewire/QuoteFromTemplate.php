<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Actions\CreateQuoteForDeal;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Modules\ModuleManager;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Ny offert från mall" (ADR-046) on Offerter, the account card and the
 * deal card: pick a template (and a customer, unless the card gives one),
 * and the new quote opens in the editor. Shown only with CRM on and
 * invoices:create, and only when the company has a template.
 */
class QuoteFromTemplate extends Component
{
    public const string NAME = 'crm.quote-from-template';

    #[Locked]
    public ?int $fixedCustomerId = null;

    /** On a deal's card: the quote is made for the deal and linked to it. */
    #[Locked]
    public ?int $dealId = null;

    /** The deal's quote when the card was drawn; see CreateQuoteForDeal. */
    #[Locked]
    public ?string $quoteSeen = null;

    #[Locked]
    public bool $boxed = false;

    public string $templateId = '';

    public string $customerId = '';

    public function mount(?int $customerId = null, bool $boxed = false, ?int $dealId = null, ?string $quoteSeen = null): void
    {
        $this->quoteSeen = $quoteSeen;
        $this->dealId = $dealId;
        $this->fixedCustomerId = $customerId;
        $this->boxed = $boxed;
    }

    public function create(CreateQuoteFromTemplate $create, CreateQuoteForDeal $createForDeal): void
    {
        $this->resetErrorBag();
        $this->validate(['templateId' => ['required', 'integer'], 'customerId' => $this->fixedCustomerId === null && $this->dealId === null ? ['required', 'integer'] : ['nullable']], [
            'templateId.required' => __('Välj en mall.'),
            'customerId.required' => __('Välj en kund.'),
        ]);

        $template = QuoteTemplate::query()->whereNull('archived_at')->findOrFail((int) $this->templateId);
        try {
            $quote = $this->dealId === null
                ? $create($this->user(), $this->company(), $template, $this->fixedCustomerId ?? (int) $this->customerId)
                : $createForDeal($this->user(), $this->company(), Deal::query()->findOrFail($this->dealId), $template, $this->quoteSeen);
        } catch (ValidationException $exception) {
            if ($this->dealId === null) {
                throw $exception;
            }

            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) $messages[0]);
            }

            return;
        }

        Toast::flash(__(':title är skapad från mallen :name. Kontrollera den och skicka den.', ['title' => $quote->title(), 'name' => $template->name]));
        $this->redirectRoute('invoicing.quotes.show', ['document' => $quote->id]);
    }

    public function render(): View
    {
        $company = $this->company();
        $visible = app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company) && $this->user()->hasTeamPermission($company, 'invoices:create');

        return view('crm::livewire.quote-from-template', [
            'templates' => $visible ? QuoteTemplate::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : collect(),
            'customers' => $visible && $this->fixedCustomerId === null && $this->dealId === null ? Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->limit(2000)->get(['id', 'number', 'name']) : collect(),
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
