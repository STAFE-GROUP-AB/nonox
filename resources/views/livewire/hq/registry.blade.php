@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6" x-data="{
    busy: false,
    error: null,
    async withPasskey(call) {
        this.error = null;
        this.busy = true;
        try {
            if (@js($fresh)) {
                await call(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            await call(await window.NonoxPasskeys.authenticate(options));
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <x-ui.card :title="__('Undanta uppgifter')" :description="__('När någon invänder mot eller begär radering av uppgifter om sig i företagsregistret: ett företagsnamn med personens namn, eller ett telefonnummer eller en e-postadress som är personens egen. Uppgifterna tas bort direkt och läses aldrig in igen.')">
        <form class="grid gap-4 sm:grid-cols-3" x-on:submit.prevent="withPasskey((credential) => $wire.exclude(credential))" novalidate>
            <x-ui.field for="registry-org" :label="__('Organisationsnummer')" error="org_number">
                <input id="registry-org" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="orgNumber" required>
            </x-ui.field>
            <x-ui.field for="registry-field" :label="__('Vad som undantas')" error="field">
                <select id="registry-field" class="{{ $input }} mt-1" wire:model="field">
                    @foreach($fields as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="registry-reason" :label="__('Skäl')" error="reason">
                <select id="registry-reason" class="{{ $input }} mt-1" wire:model="reason">
                    @foreach($reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
                <button type="submit" class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60" x-bind:disabled="busy">{{ __('Spara undantaget') }}</button>
                <p class="text-sm text-gray-600">{{ __('Bekräftas med din passkey och skrivs i behandlingshistoriken utan nummer eller namn.') }}</p>
            </div>
        </form>
    </x-ui.card>

    @if(! $hasPasskey && ! $fresh)
        <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att ändra inställningarna i HQ. Lägg till en under Profil.') }}</p>
    @endif
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

    <x-ui.card :title="__('Undantag')">
        @if($exclusions->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga undantag.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm" data-testid="registry-exclusions">
                @foreach($exclusions as $exclusion)
                    <li wire:key="exclusion-{{ $exclusion->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span><span class="tabular-nums font-medium text-ink">{{ substr($exclusion->org_number, 0, 6) }}-{{ substr($exclusion->org_number, 6) }}</span> · {{ $fields[$exclusion->field] ?? $exclusion->field }} · {{ $reasons[$exclusion->reason] ?? $exclusion->reason }} · {{ $exclusion->created_at?->translatedFormat('j M Y') }}</span>
                        <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700 disabled:opacity-60" x-bind:disabled="busy" x-on:click="withPasskey((credential) => $wire.remove(credential, {{ $exclusion->id }}))">{{ __('Ta bort undantaget') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Senaste inläsningarna')">
        @if($imports->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Registret är inte inläst. Kör php artisan registry:import scb-bulk och sedan bolagsverket-bulk.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($imports as $import)
                    <li wire:key="import-{{ $import->id }}" class="py-3">
                        <span class="font-medium text-ink">{{ $import->source }}</span> · {{ $import->status }} · {{ $import->started_at->setTimezone('Europe/Stockholm')->translatedFormat('j M Y H:i') }} · {{ __(':read rader, :written företag', ['read' => $import->rows_read, 'written' => $import->rows_written]) }}
                        @if($import->warning)<span class="mt-1 block text-accent-800">{{ $import->warning }}</span>@endif
                        @if($import->error)<span class="mt-1 block text-red-700">{{ $import->error }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
