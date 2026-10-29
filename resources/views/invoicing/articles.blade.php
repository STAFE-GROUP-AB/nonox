<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Artiklar')" :lead="__('Det du säljer, med pris och moms.')" />
        <x-ui.sales-nav />
        <livewire:invoicing.articles />
    </div>
</x-app-layout>
