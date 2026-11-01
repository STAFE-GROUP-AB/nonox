@props(['company'])

{{-- A test company (ADR-032) is never real bookkeeping; every page says so. --}}
@if($company?->isTestCompany())
    <div role="status" data-test-company-banner class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:px-6 lg:px-10">
        <span class="font-semibold">{{ __('Testföretag.') }}</span>
        {{ __('Här finns påhittade exempeluppgifter för att prova NONOX och API:t. Inget här är riktig bokföring, och fakturor och påminnelser mejlas aldrig till någon.') }}
    </div>
@endif
