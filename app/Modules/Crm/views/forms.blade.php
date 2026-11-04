<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Webbformulär')" :lead="__('Formulär för er webbplats som skapar ett prospekt med kontaktperson och samtycke, och en uppgift att följa upp.')" />
        <livewire:crm.forms />
    </div>
</x-app-layout>
