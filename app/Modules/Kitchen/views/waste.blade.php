<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Svinn')" :lead="__('Det som har slängts eller skänkts, med orsak. Varorna dras från lagret direkt. Svinn kan också registreras med mobilen under Lager → Skanna.')" />
        <livewire:kitchen.waste />
    </div>
</x-app-layout>
