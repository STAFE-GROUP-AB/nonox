<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$version->name" :lead="__('Rullande prognos för räkenskapsåret :year: utfallet till och med brytmånaden, och en prognos för resten av året. Prognosen bokförs aldrig. Skriv beloppen i kronor, intäkter och kostnader som positiva belopp.', ['year' => $version->fiscalYear->label()])" />
        <livewire:accounting.forecast-editor :version="$version" />
    </div>
</x-app-layout>
