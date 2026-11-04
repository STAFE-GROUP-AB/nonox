<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Ringlistor')" :lead="__('Listor med företag att ringa, ett i taget. Logga hur samtalet gick med ett tryck och planera nästa steg direkt.')" />
        <livewire:crm.call-lists />
    </div>
</x-app-layout>
