@php($select = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
<div class="space-y-6">
    <x-ui.card>
        <p class="text-sm text-gray-600">{{ __('Ett lokalt avtal kan ersätta delar av det centrala avtalet, till exempel en annan OB-sats eller en annan provision. Här sparas företagets avvikelser. Det centrala avtalets värden ändras aldrig, och en avvikelse används i lönen först när en person med rätt att kontrollera avtal har jämfört den med det lokala avtalet. Lönebeskedet visar när en sats kommer från det lokala avtalet.') }}</p>
    </x-ui.card>

    @if($bindings->isEmpty())
        <x-ui.card>
            <p class="text-sm text-gray-600">{{ __('Företaget är inte bundet av något avtal ännu. Lägg till avtalet under Företag och anställda.') }}</p>
        </x-ui.card>
    @endif

    @foreach($bindings as $binding)
        @php($overrides = (array) $binding->overrides)
        <x-ui.card :title="$binding->name" :description="__('Lokala avvikelser från avtalet.')">
            @if($overrides === [])
                <p class="text-sm text-gray-600">{{ __('Inga lokala avvikelser. Det centrala avtalet gäller.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Avvikelse') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Enligt lokalt avtal') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Kontrollerad') }}</th>
                                <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($overrides as $path => $override)
                                <tr wire:key="override-{{ $binding->id }}-{{ $path }}">
                                    <td class="py-2 pr-4 align-top">
                                        {{ App\Modules\Agreements\Domain\LocalOverrides::label((string) $path) }}
                                        @unless($override['applied'] ?? false)<span class="block text-xs text-gray-500">{{ __('Sparas men räknas inte av NONOX ännu.') }}</span>@endunless
                                    </td>
                                    <td class="py-2 pr-4 align-top">
                                        <span class="font-medium text-gray-900">{{ App\Modules\Agreements\Domain\LocalOverrides::describe((string) $path, $override['value'] ?? null) }}</span>
                                        @if(filled($override['description'] ?? null))<span class="block text-xs text-gray-600">{{ $override['description'] }}</span>@endif
                                        <span class="block text-xs text-gray-500">{{ ($override['origin'] ?? '') === 'AI' ? __('Läst av NONOX AI och godkänd') : __('Inlagd för hand') }}{{ str_contains((string) ($override['source'] ?? ''), '#') ? ', '.Illuminate\Support\Str::after((string) $override['source'], '#') : '' }}</span>
                                    </td>
                                    <td class="py-2 pr-4 align-top">
                                        @if(filled($override['verified_at'] ?? null))
                                            <x-ui.badge tone="success">{{ $override['verified_by'] ?? '' }}</x-ui.badge>
                                        @else
                                            <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Ej kontrollerad') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 align-top text-right whitespace-nowrap">
                                        @if($canVerify && ! filled($override['verified_at'] ?? null))
                                            <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="verify({{ $binding->id }}, @js($path))" wire:confirm="{{ __('Har du jämfört värdet med det lokala avtalet? Från nästa beräkning använder lönen det i stället för det centrala avtalets värde.') }}">{{ __('Kontrollera') }}</button>
                                        @endif
                                        @if($canManage)
                                            <button type="button" class="ml-2 text-gray-600 underline" wire:click="remove({{ $binding->id }}, @js($path))" wire:confirm="{{ __('Ta bort avvikelsen? Centrala avtalets värde gäller då igen från nästa beräkning.') }}">{{ __('Ta bort') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endforeach

    @if($canManage && $bindings->isNotEmpty())
        <x-ui.card :title="__('Lägg till avvikelse')" :description="__('Ange det lokala avtalets värde. Med NONOX AI kan du i stället ladda upp det lokala avtalet under NONOX AI och granska förslagen.')">
            <form wire:submit="record" class="grid gap-3 sm:grid-cols-2">
                <x-ui.field for="local_binding" :label="__('Avtal')" error="bindingId">
                    <select id="local_binding" wire:model.live="bindingId" class="{{ $select }}">
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($bindings as $binding)
                            <option value="{{ $binding->id }}">{{ $binding->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="local_path" :label="__('Vad avviker')" error="path">
                    <select id="local_path" wire:model="path" class="{{ $select }}" @disabled($bindingId === '')>
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($paths as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="local_value" :label="__('Värde enligt lokalt avtal')" :hint="__('Kronor (30), procent (35 %), delare (/600), veckor, timmar eller text, som det lokala avtalet säger.')" error="value">
                    <input id="local_value" type="text" wire:model="value" class="{{ $select }}">
                </x-ui.field>
                <x-ui.field for="local_page" :label="__('Sida i det lokala avtalet')" error="page">
                    <input id="local_page" type="number" min="1" wire:model="page" class="{{ $select }} sm:w-28">
                </x-ui.field>
                <x-ui.field for="local_description" :label="__('Beskrivning')" class="sm:col-span-2">
                    <input id="local_description" type="text" wire:model="description" maxlength="500" class="{{ $select }}">
                </x-ui.field>
                <div class="sm:col-span-2">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara avvikelse') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
