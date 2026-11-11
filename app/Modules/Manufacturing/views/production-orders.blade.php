<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Tillverkningsorder')" :lead="__('Det verkstaden ska tillverka: en produkt, ett antal och orderns egen stycklista. En frisläppt order reserverar komponenterna, och en färdig order tar emot produkterna i lagret till tillverkningskostnaden.')" />
        <livewire:manufacturing.production-orders />
    </div>
</x-app-layout>
