<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Inställningar för :company', ['company' => $team->name]) }}
        </h2>
    </x-slot>

    <div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-10">
        <livewire:company.company-details-form :company="$team" />

        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Personer i företaget') }}</h3>
            @livewire('teams.team-member-manager', ['team' => $team])
        </div>
    </div>
</x-app-layout>
