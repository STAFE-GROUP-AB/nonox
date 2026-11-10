<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Enheter')" :lead="__('Det artiklarna räknas, köps och säljs i: styck, meter, kvadrat, kubik och egna enheter.')" />
        <p class="text-sm"><a href="{{ route('invoicing.articles') }}" class="font-medium text-accent-700 underline">{{ __('Tillbaka till artiklar') }}</a></p>
        <livewire:invoicing.units />
    </div>
</x-app-layout>
