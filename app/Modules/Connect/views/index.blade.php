<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Anslutningar')" :lead="__('Appar som har fått ansluta till företaget och API-nycklar för egna skript. Varje anslutning gäller bara det här företaget och bara det den har fått rätt till.')" />
        <livewire:connect.connections-manager />
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
