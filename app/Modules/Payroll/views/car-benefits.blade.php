<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Förmånsbilar')" :lead="__('Bilar som anställda får använda privat: förmånsvärdet räknas efter inkomstskattelagen och kommer med i lönekörningen varje månad.')" />
        <livewire:payroll.car-benefits />
    </div>
</x-app-layout>
