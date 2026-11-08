<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Aktiebok')" :lead="__('Aktieboken visar vem som äger varje aktie. Varje ändring är en egen händelse med datum, och historiken kan inte ändras i efterhand.')" />
        <livewire:boardroom.register />
    </div>
</x-app-layout>
