<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – kunder')" :lead="__('Företagen och användarna i den här installationen. Här syns bara uppgifter om kontona: inga belopp, verifikationer, fakturor, personnummer eller uppgifter om anställda. Sidan är bara för läsning. Du kan inte logga in som någon annan, ändra eller radera något här. Varje besök skrivs i behandlingshistoriken.')" />
        <livewire:hq.customers />
    </div>
</x-app-layout>
