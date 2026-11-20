<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The company's lowest contribution margin (lägsta täckningsgrad) and what a
 * manual price under it does (ADR-082 §9, decision 12). Off until a percentage
 * is typed; changing it needs price-lists:manage.
 */
class FloorSettings extends Component
{
    use ShowsToasts;

    public string $percent = '';

    public string $policy = 'WARN';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'prices:view'), 403);

        $floor = PriceFloor::of($this->company());
        $this->percent = $floor->isOn() ? str_replace(' %', '', $floor->label()) : '';
        $this->policy = $floor->policy->value;
    }

    public function save(SetPriceFloor $set): void
    {
        $this->resetErrorBag();

        try {
            $company = $set($this->user(), $this->company(), $this->percent, PriceFloorPolicy::tryFrom($this->policy) ?? PriceFloorPolicy::Warn);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->toast($company->min_contribution_bp === null
            ? __('Lägsta täckningsgrad är avstängd.')
            : __('Lägsta täckningsgrad är satt till :floor.', ['floor' => PriceFloor::of($company)->label()]));
    }

    public function render(): View
    {
        return view('livewire.pricing.floor-settings', [
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'price-lists:manage'),
            'separate' => $this->company()->separate_supplier_approval,
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
