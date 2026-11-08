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
    <x-ui.card :title="__('Ny händelse')">
        <form class="space-y-4" x-on:submit.prevent="withPasskey((credential) => $wire.open(credential))" novalidate>
            <p class="text-sm text-gray-600">{{ __('Texten blir offentlig på /status direkt. Skriv aldrig namn på företag eller personer, och inget som hjälper en angripare. Planerat underhåll och information räknas inte som störning.') }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="st-title" class="block text-sm font-medium text-gray-700">{{ __('Rubrik') }}</label>
                    <input id="st-title" type="text" wire:model="title" maxlength="160" class="mt-1 {{ $input }}" @error('title') aria-invalid="true" aria-describedby="st-title-error" @enderror>
                    @error('title') <p id="st-title-error" class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="st-severity" class="block text-sm font-medium text-gray-700">{{ __('Allvarlighet') }}</label>
                    <select id="st-severity" wire:model="severity" class="mt-1 {{ $input }}">
                        @foreach($severities as $option)
                            <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                        @endforeach
                    </select>
                    @error('severity') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="st-body" class="block text-sm font-medium text-gray-700">{{ __('Vad vet vi just nu?') }}</label>
                <textarea id="st-body" wire:model="body" rows="3" maxlength="2000" class="mt-1 {{ $input }}" @error('body') aria-invalid="true" aria-describedby="st-body-error" @enderror></textarea>
                @error('body') <p id="st-body-error" class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60" x-bind:disabled="busy">{{ __('Publicera på statussidan') }}</button>
        </form>
    </x-ui.card>

    @if(! $hasPasskey && ! $fresh)
        <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att publicera på statussidan. Lägg till en under Profil.') }}</p>
    @endif
    <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
    @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

    <x-ui.card :title="__('Händelser')">
        @forelse($incidents as $incident)
            <article wire:key="incident-{{ $incident->id }}" class="border-b border-stone-200 py-4 last:border-b-0" data-incident="{{ $incident->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-semibold text-ink">{{ $incident->title }}</h3>
                    <x-ui.badge :tone="$incident->isOpen() ? 'accent' : 'success'">{{ __($incident->status->label()) }}</x-ui.badge>
                    <x-ui.badge>{{ __($incident->severity->label()) }}</x-ui.badge>
                </div>
                <p class="mt-1 text-sm text-gray-600 tabular-nums">{{ __('Började :tid', ['tid' => $incident->started_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}@if($incident->resolved_at) · {{ __('åtgärdad :tid', ['tid' => $incident->resolved_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}@endif</p>
                <ol class="mt-3 space-y-2 text-sm">
                    @foreach($incident->updates as $update)
                        <li wire:key="update-{{ $update->id }}"><span class="tabular-nums text-gray-500">{{ $update->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span> <strong>{{ __($update->status->label()) }}.</strong> {{ $update->body }}</li>
                    @endforeach
                </ol>

                @if($noteFor === $incident->id)
                    <form class="mt-4 space-y-3 rounded-xl ring-1 ring-stone-200 p-4" x-on:submit.prevent="withPasskey((credential) => $wire.postNote(credential))" novalidate>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="note-status-{{ $incident->id }}" class="block text-sm font-medium text-gray-700">{{ __('Ny status') }}</label>
                                <select id="note-status-{{ $incident->id }}" wire:model="noteStatus" class="mt-1 {{ $input }}">
                                    @foreach($statuses as $option)
                                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="note-severity-{{ $incident->id }}" class="block text-sm font-medium text-gray-700">{{ __('Ändra allvarlighet (valfritt)') }}</label>
                                <select id="note-severity-{{ $incident->id }}" wire:model="noteSeverity" class="mt-1 {{ $input }}">
                                    <option value="">{{ __('Behåll') }}</option>
                                    @foreach($severities as $option)
                                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="note-body-{{ $incident->id }}" class="block text-sm font-medium text-gray-700">{{ __('Anteckning') }}</label>
                            <textarea id="note-body-{{ $incident->id }}" wire:model="noteBody" rows="2" maxlength="2000" class="mt-1 {{ $input }}"></textarea>
                            @error('body') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                            @error('status') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60" x-bind:disabled="busy">{{ __('Publicera anteckningen') }}</button>
                            <button type="button" wire:click="cancelNote" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400">{{ __('Avbryt') }}</button>
                        </div>
                    </form>
                @else
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="startNote({{ $incident->id }})" class="rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-gray-800 ring-1 ring-stone-300 hover:ring-stone-400">{{ $incident->isOpen() ? __('Ny anteckning eller åtgärdad') : __('Öppna igen med en anteckning') }}</button>
                        <button type="button" class="rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-red-800 ring-1 ring-red-200 hover:ring-red-300 disabled:opacity-60" x-bind:disabled="busy" x-on:click="if (confirm(@js(__('Ta bort händelsen och hela dess historik? Bara för det som aldrig skulle ha publicerats.')))) { withPasskey((credential) => $wire.delete(credential, {{ $incident->id }})) }">{{ __('Ta bort') }}</button>
                    </div>
                @endif
            </article>
        @empty
            <p class="text-sm text-gray-600">{{ __('Inga händelser ännu. Statussidan visar då bara hur tjänsten mår just nu.') }}</p>
        @endforelse
    </x-ui.card>
</div>
