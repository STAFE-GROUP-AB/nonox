<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Mina datum')" :lead="__('Datumen som gäller företaget: moms, deklarationer, årsstämma och annat som har en sista dag, de kommande sex månaderna eller längre.')" />
        <livewire:guide.my-dates />
    </div>
</x-app-layout>
