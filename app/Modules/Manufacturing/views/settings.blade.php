@php($company = auth()->user()->currentCompany())
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inställningar för tillverkning')" :lead="__('Omkostnadspålägget som kalkylen lägger på material och arbete.')" />
        @if(auth()->user()->hasTeamPermission($company, 'manufacturing:manage'))
            <x-ui.card :title="__('Tillverkning')" :description="__('Gäller hela företaget. Att slå på eller av Tillverkning gör ägaren under Moduler.')">
                <livewire:manufacturing.settings-form :company="$company" />
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty-state :title="__('Du kan inte ändra inställningarna')">{{ __('Be ägaren eller en administratör om behörighet.') }}</x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
