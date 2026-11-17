<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Schema över företag')" :lead="__('Veckans pass i flera av dina företag bredvid varandra. Du ser bara företag där du har rätt att se schemat, och här går det inte att ändra något.')" />
        <p class="text-sm"><a href="{{ route('companies.overview') }}" class="font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline">{{ __('Tillbaka till Mina företag') }}</a></p>
        <livewire:firm.chain-schedule />
    </div>
</x-app-layout>
