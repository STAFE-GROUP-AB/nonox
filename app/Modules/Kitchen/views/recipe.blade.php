<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$recept === null ? __('Nytt recept') : __('Recept')" :lead="__('Ingredienser med nettomängd och svinn. Bruttomängden är det som tas ur lagret.')" />
        <livewire:kitchen.recipe :recept="$recept === null ? null : (int) $recept" />
    </div>
</x-app-layout>
