<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Livewire\Hq\Concerns\ReadsAsHqAdmin;
use App\Models\Company;
use App\Support\Audit\Audit;
use App\Support\Hq\CustomerDirectory;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One company in HQ's Kunder (ADR-038): its facts, members and modules.
 * Read-only, metadata only. Opening it is written to the audit log.
 */
class CustomerCompany extends Component implements SpansCompanies
{
    use ReadsAsHqAdmin;

    #[Locked]
    public int $companyId;

    public function mount(int $companyId, CustomerDirectory $directory): void
    {
        abort_unless($directory->company($companyId) instanceof Company, 404);

        $this->companyId = $companyId;

        Audit::instance('hq.company_viewed', null, ['company_id' => $companyId], $this->admin());
    }

    public function render(CustomerDirectory $directory): View
    {
        $company = $directory->company($this->companyId);
        abort_unless($company instanceof Company, 404);

        return view('livewire.hq.customer-company', [
            'company' => $company,
            'members' => $directory->members($company),
            'modules' => $directory->modulesOf($company),
        ]);
    }
}
