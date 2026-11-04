<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Offertmallar')" :lead="__('Offerter du skickar ofta, med artiklar, villkor och giltighet. Priserna hämtas från artikelregistret den dag offerten skapas.')" />
        <livewire:crm.quote-templates />
    </div>
</x-app-layout>
