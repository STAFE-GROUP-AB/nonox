<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\AccountingFirmLink;
use App\Actions\Company\UpdateAccountingFirmSettings;
use App\Domain\Company\AccountingFirms;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Accounting firm and client on the Varumärke tab (ADR-030), for the owner
 * only: a firm marks itself and chooses whether its clients see its brand;
 * a client chooses its firm. The link is branding only and gives the firm
 * no access.
 */
class FirmSettings extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public bool $isFirm = false;

    public bool $brandForClients = true;

    public string $firmId = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->ownsTeam($company), 403);

        $this->companyId = $company->id;
        $this->isFirm = (bool) $company->is_accounting_firm;
        $this->brandForClients = (bool) $company->firm_brand_for_clients;
    }

    public function saveFirm(UpdateAccountingFirmSettings $update): void
    {
        $update($this->user(), $this->company(), $this->isFirm, $this->brandForClients);

        $this->toast(match (true) {
            ! $this->isFirm => __('Företaget är inte längre markerat som redovisningsbyrå.'),
            $this->brandForClients => __('Sparat. Era kunder ser byråns logga och färger när de har valt er.'),
            default => __('Sparat. Era kunder ser sina egna varumärken.'),
        });
    }

    public function link(AccountingFirmLink $links): void
    {
        $this->validate(['firmId' => ['required', 'integer']], ['firmId.required' => __('Välj en byrå i listan.')]);

        $links->link($this->user(), $this->company(), (int) $this->firmId);

        $this->firmId = '';
        $this->toast(__('Byrån är vald. Ni och era anställda ser nu byråns logga och färger.'));
    }

    public function unlink(AccountingFirmLink $links): void
    {
        $links->unlinkFromClient($this->user(), $this->company());

        $this->toast(__('Byrån är borttagen. Ni ser ert eget varumärke igen.'));
    }

    public function removeClient(AccountingFirmLink $links, int $clientId): void
    {
        $links->unlinkFromFirm($this->user(), $this->company(), $clientId);

        $this->toast(__('Kunden visar inte längre ert varumärke.'));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.company.firm-settings', [
            'company' => $company,
            'linkedFirm' => $company->accounting_firm_id === null ? null : Company::query()->find($company->accounting_firm_id, ['id', 'name', 'is_accounting_firm', 'firm_brand_for_clients', 'archived_at']),
            // The firm sees only the names of the companies that chose it; nothing else of theirs.
            'clients' => $company->is_accounting_firm ? $company->firmClients()->orderBy('name')->get(['id', 'name', 'accounting_firm_linked_at']) : collect(),
            'eligibleFirms' => $company->is_accounting_firm ? collect() : AccountingFirms::eligibleFor($company),
        ]);
    }

    private function company(): Company
    {
        $company = Company::query()->findOrFail($this->companyId);

        abort_unless($this->user()->ownsTeam($company), 403);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
