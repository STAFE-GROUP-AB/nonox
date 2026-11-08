@props(['company'])

{{-- A test company (ADR-032) is never real bookkeeping; every page says so. A demo (ADR-077) also says that it disappears. --}}
@if($company?->isDemo())
    @php($minutes = max(0, (int) now()->diffInMinutes($company->demo_expires_at, false)))
    <div role="status" data-demo-banner class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:px-6 lg:px-10">
        <span class="font-semibold">{{ __('Demo.') }}</span>
        {{ __('Det här är ett påhittat företag som du får prova fritt. Allt du gör försvinner när tiden är ute, om :tid. Inget skickas till någon.', ['tid' => $minutes >= 60 ? __(':tim tim :min min', ['tim' => intdiv($minutes, 60), 'min' => $minutes % 60]) : __(':min min', ['min' => $minutes])]) }}
        <a href="{{ App\Support\Landing\SignupPath::url() }}" class="ml-1 font-semibold underline underline-offset-2">{{ __('Skapa konto') }}</a>
        <span class="text-amber-900">{{ __('(ett konto börjar från början, demons uppgifter följer inte med)') }}</span>
    </div>
@elseif($company?->isTestCompany())
    <div role="status" data-test-company-banner class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:px-6 lg:px-10">
        <span class="font-semibold">{{ __('Testföretag.') }}</span>
        {{ __('Här finns påhittade exempeluppgifter för att prova NONOX och API:t. Inget här är riktig bokföring, och fakturor och påminnelser mejlas aldrig till någon.') }}
    </div>
@endif
