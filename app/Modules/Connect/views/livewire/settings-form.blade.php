@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="space-y-6">
    <fieldset class="space-y-2">
        <legend class="text-sm font-semibold text-gray-900">{{ __('Vilka appar får be om åtkomst') }}</legend>
        <label class="flex items-start gap-2 text-sm"><input type="radio" value="all" wire:model.live="appPolicy" class="mt-0.5 border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>{{ __('Alla appar som är registrerade i den här NONOX-installationen. En ägare eller administratör godkänner ändå varje app med passkey.') }}</span></label>
        <label class="flex items-start gap-2 text-sm"><input type="radio" value="listed" wire:model.live="appPolicy" class="mt-0.5 border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>{{ __('Bara apparna jag markerar nedan. En app som tas bort från listan förlorar åtkomsten direkt.') }}</span></label>
        @error('app_policy') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
    </fieldset>

    @if($appPolicy === 'listed')
        <fieldset class="space-y-1">
            <legend class="text-sm font-medium text-gray-700">{{ __('Tillåtna appar') }}</legend>
            @forelse($clients as $client)
                <label class="flex items-center gap-2 text-sm" wire:key="client-{{ $client->id }}"><input type="checkbox" value="{{ $client->id }}" wire:model="allowedClients" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">{{ $client->name }}</label>
            @empty
                <p class="text-sm text-gray-500">{{ __('Inga appar är registrerade ännu. Den som driftar NONOX registrerar appar med php artisan connect:app.') }}</p>
            @endforelse
        </fieldset>
    @endif

    <x-ui.field for="connect_ip_allowlist" :label="__('Tillåtna IP-adresser för hela API:t (valfritt, en per rad)')" error="ip_allowlist">
        <textarea id="connect_ip_allowlist" rows="3" class="{{ $input }} mt-1 font-mono" wire:model="ipAllowlist" placeholder="203.0.113.10&#10;198.51.100.0/24"></textarea>
        <p class="mt-1 text-sm text-gray-500">{{ __('Tom lista: anrop från alla adresser tillåts. Gäller både appar och API-nycklar.') }}</p>
    </x-ui.field>

    <button type="submit" class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Spara') }}</button>
</form>
