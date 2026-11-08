<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Avvikelser')" :lead="__('Vad kassans försäljning borde ha använt, jämfört med vad inventeringarna visar. Det som inte förklaras av försäljning, svinn och personalmat är den oförklarade avvikelsen.')" />
        <livewire:kitchen.variance />
    </div>
</x-app-layout>
