<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\LocalAgreements;
use App\Modules\Agreements\Domain\LocalOverrides;
use App\Modules\Payroll\Models\CollectiveAgreement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lön → Avtal → Lokala avtal (ADR-033 §3, §6): the company's local
 * deviations from each central agreement, recorded by hand or accepted
 * from NONOX AI's reading of the local agreement, and verified by a person
 * with agreements:verify before a run uses them.
 */
class AgreementLocalPage extends Component
{
    use ShowsToasts;

    public string $bindingId = '';

    public string $path = '';

    public string $value = '';

    public string $page = '';

    public string $description = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'agreements:view'), 403);
    }

    public function record(LocalAgreements $local): void
    {
        $binding = CollectiveAgreement::query()->whereNotNull('agreement_key')->find((int) $this->bindingId);

        if (! $binding instanceof CollectiveAgreement) {
            $this->addError('bindingId', __('Välj avtal.'));

            return;
        }

        $rules = LocalAgreements::rules($binding);
        $value = $rules === null || $this->path === '' ? null : LocalOverrides::valueFrom($this->path, LocalOverrides::parseEntered($this->path, $this->value), $rules);

        try {
            $local->record($this->user(), $this->company(), $binding, $this->path, $value, ['page' => (int) $this->page, 'origin' => 'MANUAL', 'description' => trim($this->description) === '' ? null : trim($this->description)]);
        } catch (ValidationException $exception) {
            $this->addError('value', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->reset('path', 'value', 'page', 'description');
        $this->toast(__('Avvikelsen är sparad. Den används i lönen när en behörig person har kontrollerat den.'));
    }

    public function verify(LocalAgreements $local, int $bindingId, string $path): void
    {
        $local->verify($this->user(), $this->company(), CollectiveAgreement::query()->findOrFail($bindingId), $path);
        $this->toast(__('Avvikelsen är kontrollerad och används i lönen från nästa beräkning.'));
    }

    public function remove(LocalAgreements $local, int $bindingId, string $path): void
    {
        $local->remove($this->user(), $this->company(), CollectiveAgreement::query()->findOrFail($bindingId), $path);
        $this->toast(__('Avvikelsen är borttagen. Centrala avtalets värde gäller igen.'));
    }

    public function render(): View
    {
        $bindings = CollectiveAgreement::query()->whereNotNull('agreement_key')->orderBy('name')->get();
        $binding = $bindings->firstWhere('id', (int) $this->bindingId);
        $rules = $binding instanceof CollectiveAgreement ? LocalAgreements::rules($binding) : null;
        $paths = [];

        foreach ($rules?->codes() ?? [] as $code => $definition) {
            $paths["codes.{$code}.rate"] = LocalOverrides::label("codes.{$code}.rate").' – '.$definition['name'];

            if (($definition['kind'] ?? null) === 'COMMISSION') {
                $paths["codes.{$code}.guarantee"] = LocalOverrides::label("codes.{$code}.guarantee").' – '.$definition['name'];
            }
        }

        foreach (array_keys(LocalOverrides::PATHS) as $pattern) {
            if (! str_contains($pattern, '*')) {
                $paths[$pattern] = LocalOverrides::label($pattern).(LocalOverrides::applied($pattern) ? '' : ' '.__('(sparas, räknas inte ännu)'));
            }
        }

        return view('agreements::livewire.local', [
            'bindings' => $bindings,
            'paths' => $paths,
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'agreements:manage'),
            'canVerify' => $this->user()->hasTeamPermission($this->company(), 'agreements:verify'),
        ]);
    }

    private function company(): Company
    {
        /** @var Company */
        return $this->user()->currentCompany();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
