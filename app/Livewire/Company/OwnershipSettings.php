<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Company\ArchiveCompany;
use App\Actions\Company\TransferOwnership;
use App\Actions\Company\UnarchiveCompany;
use App\Domain\Company\ArchiveReadiness;
use App\Domain\Company\CompanyRole;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Ägarskap och arkiv" tab, for the owner only (AGENTS.md §3.5, §4,
 * ADR-024): hand the company over to another member, archive it after its
 * archive packages are made, or take it out of the archive.
 */
class OwnershipSettings extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public string $newOwnerId = '';

    public bool $confirmedExport = false;

    public function mount(Company $company): void
    {
        abort_unless(Gate::allows('transferOwnership', $company), 403);

        $this->companyId = $company->id;
    }

    public function transfer(TransferOwnership $transfer): void
    {
        try {
            $newOwner = $transfer($this->user(), $this->company(), (int) $this->newOwnerId);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        // The tab is the owner's only; the page is opened again as administrator.
        Toast::flash(__(':name är nu ägare till :company. Du är kvar som administratör.', ['name' => $newOwner->name, 'company' => $this->company()->name]));

        $this->redirectRoute('teams.show', ['company' => $this->companyId]);
    }

    public function createMissingPackages(ArchiveReadiness $readiness, CreateArchivePackage $create): void
    {
        $ordered = 0;

        foreach ($readiness->years($this->company()) as $row) {
            if ($row['current'] || $row['busy']) {
                continue;
            }

            try {
                $create($this->user(), $row['year']);
                $ordered++;
            } catch (ValidationException $exception) {
                $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

                return;
            }
        }

        $this->toast($ordered === 0
            ? __('Alla räkenskapsår har redan ett aktuellt arkivpaket eller ett som skapas.')
            : __('Arkivpaket beställda för :count räkenskapsår. De skapas i bakgrunden; ladda ner dem under Räkenskapsår när de är klara.', ['count' => $ordered]));
    }

    public function archive(ArchiveCompany $archive): void
    {
        try {
            $archive($this->user(), $this->company(), $this->confirmedExport);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->confirmedExport = false;
        $this->toast(__('Företaget är arkiverat. Allt finns kvar och kan läsas och exporteras, men inget kan ändras.'));
    }

    public function unarchive(UnarchiveCompany $unarchive): void
    {
        $unarchive($this->user(), $this->company());

        $this->toast(__('Företaget är inte längre arkiverat och kan användas som vanligt igen.'));
    }

    public function render(ArchiveReadiness $readiness): View
    {
        $company = $this->company();

        return view('livewire.company.ownership-settings', [
            'company' => $company,
            // Members with a company role who could take over; the role is read as company_role.
            'candidates' => User::query()
                ->join('team_user', 'team_user.user_id', '=', 'users.id')
                ->where('team_user.team_id', $company->id)
                ->whereIn('team_user.role', array_map(fn (CompanyRole $role): string => $role->value, CompanyRole::cases()))
                ->whereNull('users.anonymised_at')
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'team_user.role as company_role']),
            'roleLabels' => collect(CompanyRole::cases())->mapWithKeys(fn (CompanyRole $role): array => [$role->value => $role->label()])->all(),
            'years' => $readiness->years($company),
        ]);
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
