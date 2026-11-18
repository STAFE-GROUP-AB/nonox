<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Attestflöden')" :lead="__('Bestäm vem som attesterar leverantörsfakturor, betalfiler, inköpsorder, manuella verifikationer och kreditfakturor, i hur många steg och från vilket belopp. Utan eget flöde gäller standardattesten.')" />
        <livewire:approval.approval-policies />
    </div>
</x-app-layout>
