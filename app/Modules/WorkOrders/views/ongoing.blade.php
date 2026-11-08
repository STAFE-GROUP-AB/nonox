<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Pågående arbeten')" :lead="__('Upparbetat, fakturerat och förslag till justering på balansdagen. Ingenting bokförs förrän du själv bokför verifikationerna.')" />
        <livewire:work-orders.ongoing />
    </div>
</x-app-layout>
