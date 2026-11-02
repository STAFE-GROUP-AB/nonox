<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Öppna pass och byten')" :lead="__('Personalen ber om öppna pass, byten och att få lämna pass i Min sida. Inget ändras i schemat förrän du godkänner; vid godkännande kontrolleras vilotider och arbetstid igen.')" />
        <livewire:planning.requests />
    </div>
</x-app-layout>
