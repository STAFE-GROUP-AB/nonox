<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Artiklar')" :lead="__('Det du säljer, med pris och moms.')" />
        <livewire:invoicing.articles />
    </div>
</x-app-layout>
