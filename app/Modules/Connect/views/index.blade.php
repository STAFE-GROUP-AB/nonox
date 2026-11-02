<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Anslutningar')" :lead="__('Appar som har fått ansluta till företaget, API-nycklar för egna skript, webhooks som berättar när något händer och SFTP för SIE-filer. Varje anslutning gäller bara det här företaget och bara det den har fått rätt till.')" />
        @if(Route::has('pos.index') && app(\App\Modules\ModuleManager::class)->isEnabled('pos'))
            <x-ui.card :title="__('Kassa')" :description="__('Zettle och Onslip kopplas utan API-nycklar, under Kassa.')">
                <a href="{{ route('pos.index') }}" class="mt-2 inline-block font-semibold text-accent-700 underline">{{ __('Gå till Kassa') }}</a>
            </x-ui.card>
        @endif
        <livewire:connect.connections-manager />
        <livewire:connect.webhooks-manager />
        <livewire:connect.sftp-manager />
        @if(auth()->user()?->hasTeamPermission(auth()->user()->currentCompany(), 'connect:manage'))
            <livewire:connect.test-company-panel />
        @endif
    </div>
    @include('auth.partials.passkey-script')
</x-app-layout>
