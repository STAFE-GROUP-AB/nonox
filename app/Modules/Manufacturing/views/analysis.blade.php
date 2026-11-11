<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Efterkalkyl')" :lead="__('Vad som kalkylerades mot vad som rapporterades i verkstaden, per produkt och arbetssteg: hur mycket det varierar, vart det är på väg, var det köar och vad ordrarna väntar på. Bara produkt och arbetssteg, aldrig person.')" />
        <livewire:manufacturing.analysis />
    </div>
</x-app-layout>
