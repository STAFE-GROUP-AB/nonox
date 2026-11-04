<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Hq\ExcludeFromRegistry;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\RegistryExclusion;
use App\Models\RegistryImport;
use App\Support\Hq\HqAccess;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Företagsregistret in /hq (ADR-047): the latest imports with their
 * warnings, and the exclusions after an objection or erasure request about
 * data in the register. Adding or removing an exclusion is a decision: a
 * fresh passkey confirmation, written to the instance audit log with ids
 * only.
 */
class Registry extends Component
{
    use DecidesWithPasskey;
    use ShowsToasts;

    public string $orgNumber = '';

    public string $field = RegistryExclusion::ALL;

    public string $reason = 'OBJECTION';

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function exclude(?array $credential, ExcludeFromRegistry $exclude): void
    {
        $this->confirmDecision($credential);

        $exclude($this->admin(), ['org_number' => $this->orgNumber, 'field' => $this->field, 'reason' => $this->reason]);

        $this->reset('orgNumber');
        $this->toast(__('Undantaget är sparat. Uppgifterna är borttagna ur registret och läses inte in igen.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function remove(?array $credential, int $exclusionId, ExcludeFromRegistry $exclude): void
    {
        $this->confirmDecision($credential);

        $exclude->remove($this->admin(), RegistryExclusion::query()->findOrFail($exclusionId));
        $this->toast(__('Undantaget är borttaget. Uppgifterna kommer tillbaka vid nästa inläsning.'));
    }

    public function render(): View
    {
        $admin = $this->admin();

        return view('livewire.hq.registry', [
            'exclusions' => RegistryExclusion::query()->latest('id')->limit(500)->get(),
            'imports' => RegistryImport::query()->latest('id')->limit(10)->get(),
            'fields' => [
                RegistryExclusion::ALL => __('Hela företaget'),
                RegistryExclusion::NAME => __('Namnet (innehåller en persons namn)'),
                RegistryExclusion::PHONE => __('Telefonnumret'),
                RegistryExclusion::EMAIL => __('E-postadressen'),
            ],
            'reasons' => ['OBJECTION' => __('Invändning (artikel 21)'), 'ERASURE' => __('Begäran om radering (artikel 17)')],
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
        ]);
    }
}
