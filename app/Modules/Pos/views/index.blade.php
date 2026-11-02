<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Kassa')" :lead="__('Koppla kassan. Försäljningen dras från lagret, lagersaldot skickas tillbaka till kassan och varje dags försäljning blir ett verifikationsutkast i serie K som du stämmer av och bokför.')" />
        <livewire:pos.page />
    </div>
</x-app-layout>
