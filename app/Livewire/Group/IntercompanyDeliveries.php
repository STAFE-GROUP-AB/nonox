<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\SetIntercompanyReceipt;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyDelivery;
use App\Models\IntercompanyPair;
use App\Models\IntercompanyReceiptSetting;
use App\Models\User;
use App\Policies\IntercompanyDeliveryPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Intra-group invoices and paired drafts on Bokföring → Koncernmarkeringar
 * (ADR-081 E27): from which group companies the company accepts invoices
 * into its inbox, the invoices it has delivered to group companies, and its
 * paired vouchers. Only the company's own rows, in a fixed number of
 * queries; nothing about another company is read here.
 */
class IntercompanyDeliveries extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const string NAME = 'group.intercompany-deliveries';

    public const int PER_PAGE = 20;

    public string $senderOrgNumber = '';

    public function mount(): void
    {
        abort_unless(IntercompanyDeliveryPolicy::mayOpenPage($this->user(), $this->company()), 403);
    }

    public function enableReceipt(SetIntercompanyReceipt $set, ?string $orgNumber = null): void
    {
        $this->resetErrorBag();
        $set->enable($this->user(), $this->company(), $orgNumber ?? $this->senderOrgNumber);
        $this->senderOrgNumber = '';

        $this->toast(__('Företaget tar nu emot koncernfakturor från det koncernföretaget.'));
    }

    public function disableReceipt(SetIntercompanyReceipt $set, int $settingId): void
    {
        $set->disable($this->user(), $this->company(), $settingId);

        $this->toast(__('Företaget tar inte längre emot koncernfakturor från det koncernföretaget.'));
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $canReceipt = IntercompanyDeliveryPolicy::mayManageReceipt($user, $company);
        $settings = IntercompanyReceiptSetting::query()->orderBy('sender_org_number')->get();

        return view('livewire.group.intercompany-deliveries', [
            'canReceipt' => $canReceipt,
            'settings' => $settings,
            // The company's own suppliers marked as group companies, not yet accepted: one click each.
            'suggestions' => $canReceipt
                ? IntercompanyCounterparty::query()->whereNotNull('supplier_id')->whereNotIn('counterparty_org_number', $settings->pluck('sender_org_number')->all())
                    ->with('supplier:id,name')->orderBy('counterparty_org_number')->limit(20)->get()
                : collect(),
            'deliveries' => IntercompanyDeliveryPolicy::mayViewDeliveries($user, $company)
                ? IntercompanyDelivery::query()->with(['invoice', 'deliverer:id,name'])->latest('id')->paginate(self::PER_PAGE, pageName: 'leveranser')
                : null,
            'pairs' => IntercompanyDeliveryPolicy::mayViewPairs($user, $company)
                ? IntercompanyPair::query()->with(['voucher.series'])->latest('id')->paginate(self::PER_PAGE, pageName: 'par')
                : null,
        ]);
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
