<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading
            :title="__('Koncernmarkeringar')"
            :lead="__('Markera kunder och leverantörer som är koncernföretag, och vilka bokförda rader som är mellanhavanden inom koncernen. Markeringarna ligger bredvid bokföringen och ändrar aldrig en verifikation.')" />
        <livewire:group.intercompany-page />
    </div>
</x-app-layout>
