<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Regelverk')" :lead="__('Se om företaget får använda K2 eller ska använda K3 för ett räkenskapsår, svara på det som NONOX inte kan se i bokföringen och se vad som ändras innan du sparar.')">
            <x-slot:action>
                <a href="{{ route('accounting.closing') }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Till bokslutet') }}</a>
            </x-slot:action>
        </x-ui.page-heading>
        <livewire:accounting.closing-framework-page />
    </div>
</x-app-layout>
