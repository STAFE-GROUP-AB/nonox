@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-10" x-data="{
    busy: false,
    error: null,
    async createKey() {
        this.error = null;
        this.busy = true;
        try {
            if (@js($skipPasskey)) {
                await $wire.createKey(null);
                return;
            }
            if (! window.NonoxPasskeys || ! window.NonoxPasskeys.supported()) {
                this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(null) : @js(__('Sidan kunde inte ladda stödet för passkeys. Ladda om sidan och försök igen.'));
                return;
            }
            const options = await $wire.passkeyOptions();
            if (! options) { return; }
            const credential = await window.NonoxPasskeys.authenticate(options);
            await $wire.createKey(credential);
        } catch (error) {
            this.error = window.NonoxPasskeys ? window.NonoxPasskeys.errorMessage(error) : String(error);
        } finally {
            this.busy = false;
        }
    },
}">
    <section class="space-y-3">
        <h2 class="text-base font-semibold text-gray-900">{{ __('Anslutna appar') }}</h2>
        <p class="text-sm text-gray-600">{{ __('En app ansluts från appen själv: den skickar dig hit till NONOX, där en ägare eller administratör godkänner med passkey. Appen får aldrig mer än personen som godkände den får göra just nu.') }}</p>
        @if($grants->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Inga appar är anslutna.') }}</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-gray-600">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('App') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Godkänd av') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Får') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Senast använd') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($grants as $grant)
                            <tr wire:key="grant-{{ $grant->id }}">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $grant->client->name ?? __('Okänd app') }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $grant->user->name ?? '–' }}<br><span class="text-gray-500">{{ $grant->created_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span></td>
                                <td class="px-3 py-2 text-gray-700"><ul class="list-disc pl-4">@foreach($grant->scopes as $scope)<li>{{ $scopeLabels[$scope] ?? $scope }}</li>@endforeach</ul></td>
                                <td class="px-3 py-2 text-gray-700">{{ $grant->last_used_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? __('Aldrig') }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if($canManage || $grant->user_id === $userId)
                                        <button type="button" class="text-sm font-medium text-red-700 hover:underline" wire:click="revokeGrant('{{ $grant->id }}')" wire:confirm="{{ __('Koppla bort appen? Den förlorar åtkomsten direkt och måste godkännas på nytt för att ansluta igen.') }}">{{ __('Koppla bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="space-y-3">
        <h2 class="text-base font-semibold text-gray-900">{{ __('API-nycklar') }}</h2>
        <p class="text-sm text-gray-600">{{ __('För egna skript och integrationer. En nyckel gäller bara det här företaget, bara de behörigheter du väljer och bara till ett slutdatum. Den visas en gång; NONOX sparar bara ett avtryck av den. Ägaren får ett mejl om varje ny nyckel.') }}</p>

        @if($newKey)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-2" x-data="{ copied: false }">
                <p class="text-sm font-semibold text-amber-900">{{ __('Kopiera nyckeln nu. Den visas inte igen.') }}</p>
                <code class="block break-all rounded-sm bg-white px-3 py-2 font-mono text-sm text-gray-900" data-api-key>{{ $newKey }}</code>
                <div class="flex gap-3">
                    <button type="button" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" x-on:click="navigator.clipboard.writeText(@js($newKey)); copied = true">
                        <span x-show="! copied">{{ __('Kopiera') }}</span><span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
                    </button>
                    <button type="button" class="text-sm font-medium text-gray-700 hover:underline" wire:click="forgetNewKey">{{ __('Jag har sparat den') }}</button>
                </div>
            </div>
        @endif

        @if($keys->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Inga API-nycklar.') }}</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-gray-600">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('Namn') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Skapad av') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Får') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Gäller till') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Senast använd') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($keys as $key)
                            <tr wire:key="key-{{ $key->id }}">
                                <td class="px-3 py-2"><span class="font-medium text-gray-900">{{ $key->name }}</span><br><span class="font-mono text-gray-500">{{ $key->prefix }}…</span></td>
                                <td class="px-3 py-2 text-gray-700">{{ $key->creator->name ?? '–' }}</td>
                                <td class="px-3 py-2 text-gray-700"><ul class="list-disc pl-4">@foreach($key->scopes as $scope)<li>{{ $scopeLabels[$scope] ?? $scope }}</li>@endforeach</ul></td>
                                <td class="px-3 py-2 text-gray-700">
                                    {{ $key->expires_at->timezone('Europe/Stockholm')->toDateString() }}
                                    @if(! $key->isUsable())<br><span class="text-red-700">{{ __('Har gått ut') }}</span>@endif
                                </td>
                                <td class="px-3 py-2 text-gray-700">{{ $key->last_used_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') ?? __('Aldrig') }}@if($key->last_used_ip)<br><span class="text-gray-500">{{ $key->last_used_ip }}</span>@endif</td>
                                <td class="px-3 py-2 text-right">
                                    @if($canManage || $key->created_by === $userId)
                                        <button type="button" class="text-sm font-medium text-red-700 hover:underline" wire:click="revokeKey({{ $key->id }})" wire:confirm="{{ __('Återkalla nyckeln? Allt som använder den slutar fungera direkt.') }}">{{ __('Återkalla') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($canManage)
            <form x-on:submit.prevent="createKey()" class="space-y-4 rounded-lg border border-gray-200 p-4">
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Skapa API-nyckel') }}</h3>
                <x-ui.field for="connect_key_name" :label="__('Namn')" error="name">
                    <input id="connect_key_name" type="text" class="{{ $input }} mt-1" wire:model="keyName" maxlength="100" placeholder="{{ __('Till exempel Kassaexport') }}">
                </x-ui.field>
                @php($offered = array_merge(...array_values($scopeGroups ?: [[]])))
                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Behörigheter') }}</legend>
                    <p class="text-sm text-gray-600">{{ __('Bara behörigheter som du själv har visas. Moduler som inte är påslagna finns inte med.') }}</p>
                    @if($offered !== [])
                        @php($full = array_diff($offered, $keyScopes) === [])
                        <div class="rounded-lg border px-3 py-2 {{ $full ? 'border-accent-600 bg-accent-50' : 'border-gray-200 bg-gray-50' }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-gray-900">{{ __('Full behörighet: allt som du själv har') }}</span>
                                <button type="button" wire:click="toggleAllScopes" class="text-sm font-medium text-accent-700 hover:underline">{{ $full ? __('Avmarkera allt') : __('Välj allt') }}</button>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Ge bara nyckeln det den behöver. Full behörighet ger nyckeln samma åtkomst som du har, till exempel att bokföra och skicka fakturor.') }}</p>
                        </div>
                    @endif
                    @foreach($scopeGroups as $group => $scopes)
                        <fieldset class="rounded-lg border border-gray-200 p-3" wire:key="scope-group-{{ $group }}">
                            <legend class="sr-only">{{ $groupLabels[$group] }}</legend>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-gray-900" aria-hidden="true">{{ $groupLabels[$group] }}</span>
                                <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="toggleScopeGroup(@js($group))">
                                    {{ array_diff($scopes, $keyScopes) === [] ? __('Avmarkera alla') : __('Välj alla') }}<span class="sr-only"> {{ __('i :group', ['group' => $groupLabels[$group]]) }}</span>
                                </button>
                            </div>
                            <div class="mt-2 space-y-1">
                                @foreach($scopes as $scope)
                                    <label class="flex items-start gap-2 text-sm" wire:key="scope-{{ $scope }}"><input type="checkbox" value="{{ $scope }}" wire:model.live="keyScopes" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500"><span>{{ $scopeLabels[$scope] }} <span class="font-mono text-xs text-gray-500">{{ $scope }}</span></span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    @error('scopes') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    @error('scopes.0') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </fieldset>
                <x-ui.field for="connect_key_expires" :label="__('Gäller till och med')" error="expires_on">
                    <input id="connect_key_expires" type="date" class="{{ $input }} mt-1" wire:model="keyExpiresOn">
                </x-ui.field>
                <x-ui.field for="connect_key_ips" :label="__('Tillåtna IP-adresser (valfritt, en per rad)')" error="ip_allowlist">
                    <textarea id="connect_key_ips" rows="2" class="{{ $input }} mt-1 font-mono" wire:model="keyIpAllowlist" placeholder="203.0.113.10&#10;198.51.100.0/24"></textarea>
                </x-ui.field>
                @if(! $hasPasskey && ! $skipPasskey)
                    <p class="text-sm text-red-700">{{ __('Du behöver en passkey för att skapa API-nycklar. Lägg till en under Profil.') }}</p>
                @else
                    <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700" x-bind:disabled="busy">
                        <span x-show="! busy">{{ __('Skapa med passkey') }}</span>
                        <span x-show="busy" x-cloak>{{ __('Väntar på passkeyn…') }}</span>
                    </button>
                @endif
                <p class="text-sm text-red-700" role="alert" x-show="error" x-text="error" x-cloak></p>
                @error('passkey') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            </form>
        @endif
    </section>
</div>
