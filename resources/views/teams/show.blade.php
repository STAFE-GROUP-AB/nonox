<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Inställningar för :company', ['company' => $team->name]) }}
        </h2>
    </x-slot>

    @php
        $tabs = array_filter([
            'uppgifter' => __('Uppgifter och personer'),
            'varumarke' => Gate::allows('update', $team) ? __('Varumärke') : null,
            'moduler' => auth()->user()->hasTeamPermission($team, 'modules:manage') ? __('Moduler') : null,
        ]);
        $tab = array_key_exists((string) request('flik'), $tabs) ? (string) request('flik') : 'uppgifter';
    @endphp

    <div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-10">
        @if(count($tabs) > 1)
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Företagsinställningar') }}">
                @foreach($tabs as $key => $label)
                    <a href="{{ route('teams.show', ['company' => $team->id, 'flik' => $key]) }}" @class([
                        'rounded-full px-4 py-1.5 text-sm font-medium',
                        'bg-accent-600 text-white' => $tab === $key,
                        'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $tab !== $key,
                    ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        @if($tab === 'moduler')
            <livewire:modules.company-modules-tab :company="$team" />
        @elseif($tab === 'varumarke')
            <livewire:company.brand-settings :company="$team" />
        @else
            <livewire:company.company-details-form :company="$team" />

            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Personer i företaget') }}</h3>
                @livewire('teams.team-member-manager', ['team' => $team])
            </div>
        @endif
    </div>
</x-app-layout>
