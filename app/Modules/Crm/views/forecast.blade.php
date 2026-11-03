<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Prognos och mål')" :lead="__('Vad de öppna affärerna väntas ge per månad, viktat med sannolikheten, bredvid det som faktiskt har vunnits. Och hur långt varje säljare har kommit mot månadens mål.')" />
        <livewire:crm.forecast />
    </div>
</x-app-layout>
