<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Modules\ModuleManager;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
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

    #[Locked]
    public bool $boxed = false;

    public string $templateId = '';

    public string $customerId = '';

    public function mount(?int $customerId = null, bool $boxed = false): void
    {
        $this->fixedCustomerId = $customerId;
        $this->boxed = $boxed;
    }

    public function create(CreateQuoteFromTemplate $create): void
    {
        $this->resetErrorBag();
        $this->validate(['templateId' => ['required', 'integer'], 'customerId' => $this->fixedCustomerId === null ? ['required', 'integer'] : ['nullable']], [
            'templateId.required' => __('Välj en mall.'),
            'customerId.required' => __('Välj en kund.'),
        ]);

        $template = QuoteTemplate::query()->whereNull('archived_at')->findOrFail((int) $this->templateId);
        $quote = $create($this->user(), $this->company(), $template, $this->fixedCustomerId ?? (int) $this->customerId);

        Toast::flash(__(':title är skapad från mallen :name. Kontrollera den och skicka den.', ['title' => $quote->title(), 'name' => $template->name]));
        $this->redirectRoute('invoicing.quotes.show', ['document' => $quote->id]);
    }

    public function render(): View
    {
        $company = $this->company();
        $visible = app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company) && $this->user()->hasTeamPermission($company, 'invoices:create');

        return view('crm::livewire.quote-from-template', [
            'templates' => $visible ? QuoteTemplate::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : collect(),
            'customers' => $visible && $this->fixedCustomerId === null ? Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->limit(2000)->get(['id', 'number', 'name']) : collect(),
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
