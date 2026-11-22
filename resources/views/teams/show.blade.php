<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Inställningar för :company', ['company' => $team->name]) }}
        </h1>
    </x-slot>

    @php
        $tabs = array_filter([
            'uppgifter' => __('Uppgifter och personer'),
            'varumarke' => Gate::allows('update', $team) ? __('Varumärke') : null,
            'moduler' => auth()->user()->hasTeamPermission($team, 'modules:manage') ? __('Moduler') : null,
            // Dimension scopes (ADR-081 gap 10): owner and admin.
            'enheter' => auth()->user()->hasTeamPermission($team, 'members:scope') ? __('Behörighet per enhet') : null,
            // The signing engine's retention and a signer's register extract and erasure (ADR-046 gap 11): owner and admin.
            'signering' => auth()->user()->hasTeamPermission($team, 'signing:settings') || auth()->user()->hasTeamPermission($team, 'signing:hold') || auth()->user()->hasTeamPermission($team, 'signing:privacy') ? __('Signering') : null,
            'agarskap' => Gate::allows('transferOwnership', $team) ? __('Ägarskap och arkiv') : null,
        ]);
        $tab = array_key_exists((string) request('flik'), $tabs) ? (string) request('flik') : 'uppgifter';
    @endphp

    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-8">
        @if(count($tabs) > 1)
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Företagsinställningar') }}">
                @foreach($tabs as $key => $label)
                    <a href="{{ route('teams.show', ['company' => $team->id, 'flik' => $key]) }}" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => $tab === $key,
                        'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $tab !== $key,
                    ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        @if($tab === 'signering')
            @if(auth()->user()->hasTeamPermission($team, 'signing:settings'))
                <livewire:company.signing-seal-settings :company="$team" />
                <livewire:company.signing-verify-settings :company="$team" />
                <livewire:company.signing-provider-settings :company="$team" />
            @endif
            @if(auth()->user()->hasTeamPermission($team, 'signing:settings') || auth()->user()->hasTeamPermission($team, 'signing:hold'))
                <livewire:company.signing-retention-settings :company="$team" />
            @endif
            @if(auth()->user()->hasTeamPermission($team, 'signing:privacy'))
                <livewire:company.signing-privacy :company="$team" />
            @endif
        @elseif($tab === 'agarskap')
            <livewire:company.ownership-settings :company="$team" />
        @elseif($tab === 'enheter')
            <livewire:company.dimension-scopes :company="$team" />
        @elseif($tab === 'moduler')
            <livewire:modules.company-modules-tab :company="$team" />
        @elseif($tab === 'varumarke')
            <livewire:company.brand-settings :company="$team" />

            @if(auth()->user()->ownsTeam($team))
                {{-- Accounting firm and client (ADR-030). --}}
                <livewire:company.firm-settings :company="$team" />
            @endif
        @else
            <livewire:company.company-details-form :company="$team" />

            @if(auth()->user()->ownsTeam($team))
                <livewire:company.passkey-policy :company="$team" />
            @endif

            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Personer i företaget') }}</h3>
                @livewire('teams.team-member-manager', ['team' => $team])
            </div>
        @endif
    </div>
</x-app-layout>
