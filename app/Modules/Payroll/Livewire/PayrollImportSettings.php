<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\PayrollImportSources;
use App\Modules\Payroll\Models\PayrollImportMapping;
use App\Modules\Payroll\Models\PayrollImportSource;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Löneunderlag in the payroll module's settings: the systems payroll files
 * come from, and per system which pay code each external code becomes.
 */
class PayrollImportSettings extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public string $newSourceName = '';

    /** @var array<int, array{code: string, choice: string}> new mapping per source id */
    public array $newMapping = [];

    public function mount(int $companyId): void
    {
        $company = Company::query()->findOrFail($companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'payroll:settings'), 403);

        $this->companyId = $company->id;
    }

    public function addSource(PayrollImportSources $sources): void
    {
        $source = $sources->create($this->user(), $this->company(), $this->newSourceName);
        $this->newSourceName = '';

        $this->toast(__(':name är tillagt som källsystem.', ['name' => $source->name]));
    }

    public function deleteSource(int $sourceId, PayrollImportSources $sources): void
    {
        $source = CurrentCompany::run($this->company(), fn (): PayrollImportSource => PayrollImportSource::query()->findOrFail($sourceId));
        $sources->delete($this->user(), $this->company(), $source);

        $this->toast(__(':name är borttaget.', ['name' => $source->name]));
    }

    public function map(int $sourceId, string $code, string $choice, PayrollImportSources $sources): void
    {
        if ($choice === '') {
            return;
        }

        $source = CurrentCompany::run($this->company(), fn (): PayrollImportSource => PayrollImportSource::query()->findOrFail($sourceId));
        $sources->map($this->user(), $this->company(), $source, $code, $choice === 'ignore' ? null : (int) $choice, $choice === 'ignore');

        $this->toast(__('Kopplingen för :code är sparad.', ['code' => mb_strtoupper(trim($code))]));
    }

    public function addMapping(int $sourceId, PayrollImportSources $sources): void
    {
        $code = (string) ($this->newMapping[$sourceId]['code'] ?? '');
        $choice = (string) ($this->newMapping[$sourceId]['choice'] ?? '');
        $source = CurrentCompany::run($this->company(), fn (): PayrollImportSource => PayrollImportSource::query()->findOrFail($sourceId));
        $sources->map($this->user(), $this->company(), $source, $code, in_array($choice, ['', 'ignore'], true) ? null : (int) $choice, $choice === 'ignore');
        unset($this->newMapping[$sourceId]);

        $this->toast(__('Kopplingen för :code är sparad.', ['code' => mb_strtoupper(trim($code))]));
    }

    public function forget(int $mappingId, PayrollImportSources $sources): void
    {
        $mapping = CurrentCompany::run($this->company(), fn (): PayrollImportMapping => PayrollImportMapping::query()->findOrFail($mappingId));
        $sources->forget($this->user(), $this->company(), $mapping);

        $this->toast(__('Kopplingen för :code är borttagen.', ['code' => $mapping->external_code]));
    }

    public function render(): View
    {
        return CurrentCompany::run($this->company(), fn (): View => view('payroll::livewire.import-settings', [
            'sources' => PayrollImportSource::query()->with(['mappings' => fn ($query) => $query->orderBy('external_code')])->orderBy('name')->get(),
            'payCodes' => PayrollImportSources::payCodes(),
        ]));
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
