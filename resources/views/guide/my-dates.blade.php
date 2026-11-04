<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Mina datum')" :lead="__('Datumen som gäller företaget de kommande 15 månaderna: moms, deklarationer, årsstämma och annat som har en sista dag.')" />
        <livewire:guide.my-dates />
    </div>
</x-app-layout>
