<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personer')" :lead="__('Kontaktpersonerna hos dina kunder och prospekt, med roll och rättslig grund.')" />
        <livewire:crm.people />
    </div>
</x-app-layout>
