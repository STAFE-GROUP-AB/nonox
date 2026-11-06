{{-- Mina datum for a company that has turned Företagsguiden off (App\Livewire\Guide\MyDates). --}}
<div class="space-y-6">
    <x-ui.empty-state :title="__('Företagsguiden är avstängd för företaget')" data-dates-off>
        {{ __('Mina datum, påminnelserna och kalenderlänkarna hör till Företagsguiden. En ägare eller administratör kan slå på den igen under företagets uppgifter.') }}
        <x-slot:action>
            <a href="{{ route('teams.show', $company->id) }}" class="font-semibold text-accent-700 underline hover:text-accent-800">{{ __('Öppna företagets uppgifter') }}</a>
        </x-slot:action>
    </x-ui.empty-state>

    {{-- Skatteverket's customer events do not belong to the guide, so they are shown also while it is off (ADR-054 stage 4). --}}
    <livewire:guide.skatteverket-events />
</div>
