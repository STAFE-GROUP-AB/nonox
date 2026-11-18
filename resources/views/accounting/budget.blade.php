<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$version->name" :lead="__('Budget för räkenskapsåret :year. Skriv beloppen i kronor: en rad märkt Intäkt eller Kostnad tar ett positivt belopp, och en intäkt på en kostnadsrad skrivs med minus.', ['year' => $version->fiscalYear->label()])" />
        <livewire:accounting.budget-editor :version="$version" />
    </div>
</x-app-layout>
