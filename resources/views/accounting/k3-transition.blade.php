<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Övergång till K3')" :lead="__('Det första året som företaget följer K3: skillnaderna mot K2 som bokförs direkt mot eget kapital per övergångsdagen, avstämningen och upplysningarna i årsredovisningen.')" />
        <livewire:accounting.k3-transition-page />
    </div>
</x-app-layout>
