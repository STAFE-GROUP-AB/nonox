@php($company = auth()->user()->currentCompany())
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inställningar för lagret')" :lead="__('Värderingsmetod, negativt saldo och kontona för lagerförändringen vid bokslutet.')" />
        @if(auth()->user()->hasTeamPermission($company, 'inventory:settings'))
            <x-ui.card :title="__('Lager')" :description="__('Gäller hela företaget. Att slå på eller av Lager gör ägaren under Moduler.')">
                <livewire:inventory.settings-form :company="$company" />
            </x-ui.card>
            <x-ui.card :title="__('Etiketter')" :description="__('Mallar för pris- och hyllkantsetiketter och för etiketter på lagerställen, med förhandsvisning.')">
                <x-ui.link :href="route('inventory.labels')">{{ __('Öppna etikettstudion') }}</x-ui.link>
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty-state :title="__('Du kan inte ändra inställningarna')">{{ __('Be ägaren eller en administratör om behörighet.') }}</x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
