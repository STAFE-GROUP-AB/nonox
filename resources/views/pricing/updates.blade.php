<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Prisuppdatering')" :lead="__('Ändra många priser på en gång: med en procent, ett belopp, ett index eller från en fil. Du ser alla nya priser innan något sparas.')" />
        <livewire:pricing.price-updates />
    </div>
</x-app-layout>
