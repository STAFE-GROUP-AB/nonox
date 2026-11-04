<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Utskick')" :lead="__('Nyhetsbrev till ett segment, med avregistrering i varje mejl och statistik över klick. Öppningar mäts inte.')" />
        <livewire:crm.mailings />
    </div>
</x-app-layout>
