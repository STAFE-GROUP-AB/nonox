<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Schemaförslag')" :lead="__('Låt NONOX föreslå vem som ska ta de öppna passen, och vilka nya pass ni behöver enligt prognosen, inom budgeten. Förslaget följer vilotider, frånvaro, kompetens och vad personalen har sagt, fördelar kvällar och helger jämnt och visar varför. Du godkänner det du vill ha, och det blir utkast i schemat.')" />
        <livewire:planning.proposals />
    </div>
</x-app-layout>
