@php($company = auth()->user()->currentCompany())
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inställningar för CRM')" :lead="__('När en kund räknas som inaktiv, och när gamla kontakter gallras.')" />
        @if(auth()->user()->hasTeamPermission($company, 'crm:settings'))
            <x-ui.card :title="__('CRM')" :description="__('Gäller hela företaget. Att slå på eller av CRM gör ägaren under Moduler.')">
                <livewire:crm.settings-form :company="$company" />
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty-state :title="__('Du kan inte ändra inställningarna')">{{ __('Be ägaren eller en administratör om behörighet.') }}</x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
