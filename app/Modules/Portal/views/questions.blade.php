<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Frågor från kunder')" :lead="__('Frågor och bestridanden som kunderna har skrivit i kundportalen.')" />
        <livewire:portal.questions />
    </div>
</x-app-layout>
