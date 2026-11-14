@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card :title="__('Det här visar skärmen')">
        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700">
            <li>{{ __('I dag: de tillverkningsorder som planen har arbete på i dag, med arbetssteg, arbetsstation och klockslag.') }}</li>
            <li>{{ __('Försenat: de order som planen räknar med blir klara efter det lovade datumet, och hur många arbetsdagar det skiljer.') }}</li>
            <li>{{ __('Ska levereras: det som är lovat de närmaste fem arbetsdagarna, med planens bedömning.') }}</li>
            <li>{{ __('Väntar och stopp: arbetssteg som väntar på material eller arbete enligt verkstadsterminalen, och arbetsstationer som står still.') }}</li>
            <li>{{ __('Skärmen hämtar läget var 30:e sekund. Den bygger på den senaste planen under Tillverkning, Planering.') }}</li>
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Det här visar skärmen aldrig')">
        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700">
            <li>{{ __('Inga namn på personer: inte vem som gör vad, inte vem som är frånvarande och inte hur mycket någon har arbetat.') }}</li>
            <li>{{ __('Inga kundnamn. Ordern visas med sitt nummer och sin produkt.') }}</li>
            <li>{{ __('Inte orsaken som skrivits in när ett arbetssteg väntar, eftersom den kan nämna en person. Bara att det väntar, och på vad.') }}</li>
            <li>{{ __('Inga belopp, timkostnader eller kalkyler.') }}</li>
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Förhandsvisning')" :description="__('Öppna tavlan här för att se exakt det som skärmen visar just nu.')">
        <a href="{{ route('manufacturing.wallScreen.preview') }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">{{ __('Visa tavlan') }}</a>
    </x-ui.card>

    @if($pairing)
        <x-ui.card :title="__('Koppla :name', ['name' => $pairing['device']])" :description="__('Öppna länken i skärmens webbläsare, eller skanna QR-koden med den. Koden gäller till :time och kan bara användas en gång.', ['time' => $pairing['expires']])">
            <div class="flex flex-wrap items-center gap-6">
                <div class="size-48 shrink-0 [&_svg]:size-full" aria-hidden="true">{!! $pairingQr !!}</div>
                <div class="space-y-2">
                    <p class="text-3xl font-bold tracking-widest tabular-nums text-ink">{{ $pairing['code'] }}</p>
                    <p class="break-all text-sm"><a href="{{ $pairing['url'] }}" target="_blank" rel="noopener" class="font-semibold text-accent-700 underline">{{ $pairing['url'] }}</a></p>
                    <p class="text-sm text-gray-600">{{ __('Skärmen kommer ihåg kopplingen. Ställ in webbläsaren på helskärm och låt sidan vara öppen.') }}</p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Skärmar')">
        @if($canPlan)
            <form wire:submit="registerScreen" class="mb-6 flex flex-wrap items-end gap-3">
                <x-ui.field for="screen-name" :label="__('Namn på skärmen')" error="deviceName">
                    <input id="screen-name" wire:model="deviceName" maxlength="100" class="{{ $input }} w-64" placeholder="{{ __('Tv:n i verkstaden') }}" required>
                </x-ui.field>
                <x-button type="submit">{{ __('Lägg till skärm') }}</x-button>
            </form>
        @endif

        @if($screens->isEmpty())
            <x-ui.empty-state :title="__('Inga skärmar ännu')">{{ $canPlan ? __('Lägg till en skärm och öppna länken med koden i skärmens webbläsare.') : __('En planerare lägger till skärmar här.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-100">
                @foreach($screens as $screen)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="screen-{{ $screen->id }}">
                        <div>
                            <span class="font-semibold text-ink">{{ $screen->name }}</span>
                            @if($screen->revoked_at)
                                <x-ui.badge>{{ __('Spärrad') }}</x-ui.badge>
                            @elseif($screen->paired_at)
                                <x-ui.badge>{{ __('Kopplad') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Väntar på koppling') }}</x-ui.badge>
                            @endif
                            @if($screen->last_seen_at)
                                <span class="ms-2 text-sm text-gray-500">{{ __('Senast sedd :time', ['time' => $screen->last_seen_at->format('Y-m-d H:i')]) }}</span>
                            @endif
                        </div>
                        @if($canPlan)
                            <div class="whitespace-nowrap">
                                @if(! $screen->paired_at && ! $screen->revoked_at)
                                    <button type="button" wire:click="renewCode({{ $screen->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Ny kopplingskod') }}</button>
                                @endif
                                @if(! $screen->revoked_at)
                                    <button type="button" wire:click="revokeScreen({{ $screen->id }})" wire:confirm="{{ __('Spärra skärmen? Den slutar visa tavlan direkt och måste kopplas på nytt.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Spärra') }}</button>
                                @endif
                                <button type="button" wire:click="removeScreen({{ $screen->id }})" wire:confirm="{{ __('Ta bort skärmen från listan? Den slutar visa tavlan direkt.') }}" class="ms-3 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
