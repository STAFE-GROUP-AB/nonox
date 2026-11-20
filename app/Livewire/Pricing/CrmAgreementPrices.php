<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The card "Prisavtal" on an agreement in the CRM agreement register
 * (ADR-082 §4, P5): the price agreements linked to it, and a way to make
 * one for the agreement's customer. Only on the CRM page, so only while CRM
 * is on, and only for someone who may see prices; nothing for others.
 */
class CrmAgreementPrices extends Component
{
    #[Locked]
    public int $agreementId;

    public function mount(int $agreementId): void
    {
        $this->agreementId = $agreementId;
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $agreement = Agreement::query()->findOrFail($this->agreementId);
        $visible = $user->can('viewAny', [PriceAgreement::class, $company]);

        return view('livewire.pricing.crm-agreement-prices', [
            'visible' => $visible,
            'linked' => $visible ? PriceAgreement::query()->where('crm_agreement_id', $agreement->id)->orderByDesc('number')->get() : collect(),
            'customerId' => $visible ? Account::query()->whereKey($agreement->account_id)->value('customer_id') : null,
            'canManage' => $user->can('create', [PriceAgreement::class, $company]),
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
