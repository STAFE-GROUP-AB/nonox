<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Verkstadsterminal')" :lead="__('Surfplattor i verkstaden där var och en trycker på sin bild, skriver sin kod och rapporterar tid på öppna tillverkningsorder. Tiden blir arbetskostnad på ordern.')" />
        <livewire:manufacturing.terminal-admin />
    </div>
</x-app-layout>
