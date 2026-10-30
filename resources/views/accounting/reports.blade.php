<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8 print:py-0">
        <x-ui.page-heading :title="__('Rapporter')" :lead="__('Hur det går och var pengarna finns. Välj period, och för restauranger och avdelningar även kostnadsställe.')" class="print:hidden" />
        <div class="print:hidden"><x-ui.bookkeeping-nav /></div>
        <p class="hidden print:block text-sm text-gray-700">{{ auth()->user()->currentCompany()?->legal_name ?: auth()->user()->currentCompany()?->name }} · {{ auth()->user()->currentCompany()?->org_number }} · {{ __('Utskrivet :date', ['date' => now()->toDateString()]) }}</p>
        <livewire:accounting.reports />
    </div>
</x-app-layout>
