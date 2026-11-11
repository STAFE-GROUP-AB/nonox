<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Ny tillverkningsorder')" :lead="__('Välj vad som ska tillverkas, från en artikel eller en rad på en kundorder. Ordern får en egen kopia av stycklistan, som kan ändras medan ordern är ett utkast.')" />
        <livewire:manufacturing.production-order-create />
    </div>
</x-app-layout>
