<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Artiklar')" :lead="__('Det du säljer, med pris och moms.')" />
        <x-ui.registers-nav />
        <livewire:invoicing.articles />
    </div>
</x-app-layout>
