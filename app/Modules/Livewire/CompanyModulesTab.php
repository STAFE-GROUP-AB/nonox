<?php

declare(strict_types=1);

namespace App\Modules\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\OfferedTo;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Moduler" tab in company settings: a card per installed module, turned
 * on or off with a confirmation that says what happens (PAYROLL_MODULE.md
 * A.8). Blockers keep a module on; warnings must be confirmed by typing the
 * module's name.
 */
class CompanyModulesTab extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public ?string $confirming = null;

    public ?string $action = null;

    public string $typedName = '';

    public ?string $settingsFor = null;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'modules:manage'), 403);

        $this->companyId = $company->id;
    }

    public function ask(string $key, string $action): void
    {
        abort_unless(app(ModuleRegistry::class)->has($key) && in_array($action, ['enable', 'disable'], true), 404);

        $this->confirming = $key;
        $this->action = $action;
        $this->typedName = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('confirming', 'action', 'typedName');
    }

    public function confirm(ModuleManager $modules, ModuleRegistry $registry): void
    {
        $key = (string) $this->confirming;
        $module = $registry->get($key);

        if ($this->action === 'enable') {
            $modules->enable($key, $this->company(), $this->user());
            $this->cancel();
            $this->toast(__('Modulen :module är påslagen.', ['module' => $module->name()]));

            return;
        }

        $warnings = $modules->disableCheck($key, $this->company())->warnings;
        $typedOk = mb_strtolower(trim($this->typedName)) === mb_strtolower($module->name());

        if ($warnings !== [] && ! $typedOk) {
            $this->addError('typedName', __('Skriv :module för att bekräfta.', ['module' => $module->name()]));

            return;
        }

        $modules->disable($key, $this->company(), $this->user(), confirmed: $typedOk);
        $this->cancel();
        $this->toast(__('Modulen :module är avstängd. Inga uppgifter har tagits bort.', ['module' => $module->name()]));
    }

    public function toggleSettings(string $key): void
    {
        $this->settingsFor = $this->settingsFor === $key ? null : $key;
    }

    public function render(ModuleManager $modules, ModuleRegistry $registry): View
    {
        $company = $this->company();
        $cards = [];

        foreach ($registry->all() as $key => $module) {
            $enabled = $modules->isEnabled($key, $company);

            // A module for some companies only (aktiebolag) is not offered to others, unless it is already on.
            if (! $enabled && $module instanceof OfferedTo && ! $module->isOfferedTo($company)) {
                continue;
            }

            $cards[] = [
                'module' => $module,
                'enabled' => $enabled,
                'dependencies' => array_map(fn (string $dependency): string => $registry->get($dependency)->name(), $module->dependencies()),
                'check' => $this->confirming === $key
                    ? ($this->action === 'enable' ? $modules->enableCheck($key, $company) : $modules->disableCheck($key, $company))
                    : null,
            ];
        }

        return view('modules.company-modules-tab', ['cards' => $cards, 'company' => $company]);
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
