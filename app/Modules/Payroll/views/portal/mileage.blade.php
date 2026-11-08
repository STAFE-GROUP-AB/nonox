<x-payroll::portal-layout :title="__('Körjournal')">
    <p><a href="{{ route('payroll.portal.home') }}" class="text-sm font-medium text-accent-700 underline">{{ __('Tillbaka till Min sida') }}</a></p>
    <x-ui.page-heading :title="__('Körjournal')" :lead="__('Dina resor i tjänsten. Skicka in en månads resor som milersättning, så betalas de med lönen.')" />
    <livewire:payroll.my-mileage />
</x-payroll::portal-layout>
