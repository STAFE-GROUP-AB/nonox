@php($company = auth()->user()->currentCompany())
<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Etiketter')" :lead="__('Mallar för artikel- och platsetiketter: storlek, ark eller etikettskrivare, och vad som ska synas. Förhandsvisningen visar hur etiketten skrivs ut.')" />
        @if(auth()->user()->hasTeamPermission($company, 'inventory:settings'))
            <livewire:inventory.labels />
        @else
            <x-ui.card>
                <x-ui.empty-state :title="__('Du kan inte ändra etikettmallarna')">{{ __('Be ägaren eller en administratör om behörighet.') }}</x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
