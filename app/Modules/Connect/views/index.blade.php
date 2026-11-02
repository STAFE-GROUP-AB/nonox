<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Anslutningar')" :lead="__('Appar som har fått ansluta till företaget, API-nycklar för egna skript, webhooks som berättar när något händer och SFTP för SIE-filer. Varje anslutning gäller bara det här företaget och bara det den har fått rätt till.')" />
        <livewire:connect.connections-manager />
        <livewire:connect.webhooks-manager />
        <livewire:connect.sftp-manager />
        @if(auth()->user()?->hasTeamPermission(auth()->user()->currentCompany(), 'connect:manage'))
            <livewire:connect.test-company-panel />
        @endif
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
