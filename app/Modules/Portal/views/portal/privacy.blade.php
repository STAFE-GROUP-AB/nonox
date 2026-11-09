@extends('portal::portal.layout', ['settings' => $settings, 'company' => $company, 'title' => __('Så hanteras dina personuppgifter')])

{{-- TODO(legal-verify): L1, L2, L3 — draft information under GDPR art. 13 (docs/juridik/kundportal-integritet-utkast.md), for a lawyer's review before the module is offered. --}}
@php($name = $company->legal_name ?: $company->name)

@section('content')
    <article class="max-w-2xl rounded-2xl bg-white p-6 ring-1 ring-stone-200 sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Så hanteras dina personuppgifter i kundportalen') }}</h1>

        <h2 class="mt-6 text-lg font-semibold">{{ __('Vem ansvarar') }}</h2>
        <p class="mt-1 text-gray-700">{{ __(':company är personuppgiftsansvarig för dina uppgifter i kundportalen:org. NONOX driver tjänsten åt :company som personuppgiftsbiträde.', ['company' => $name, 'org' => $company->org_number ? ' ('.__('org.nr :number', ['number' => $company->org_number]).')' : '']) }}@if($company->email) {{ __('Frågor går till :email.', ['email' => $company->email]) }}@endif</p>

        <h2 class="mt-6 text-lg font-semibold">{{ __('Varför') }}</h2>
        <p class="mt-1 text-gray-700">{{ __('För att du ska kunna se fakturor, betalningsuppgifter och offerter, godkänna offerter och ställa frågor om dem. Är du själv kund hos :company behandlas uppgifterna för att fullgöra avtalet med dig. Är du kontaktperson för ett företag som är kund behandlas de för att :company har ett berättigat intresse av att fullgöra sitt avtal med kunden.', ['company' => $name]) }}</p>

        <h2 class="mt-6 text-lg font-semibold">{{ __('Vad som sparas') }}</h2>
        <p class="mt-1 text-gray-700">{{ __('Din e-postadress och ditt namn, vilka kunder du har tillgång till, dina frågor och svaren på dem, och när du loggar in och vilka fakturor du öppnar. IP-adress och webbläsare sparas bara när du loggar in och när du godkänner en offert.') }}</p>

        <h2 class="mt-6 text-lg font-semibold">{{ __('Hur länge') }}</h2>
        <p class="mt-1 text-gray-700">{{ __('En inbjudan som inte används tas bort efter 14 dagar. Har du inte loggat in på ett år får du ett mejl, och 30 dagar senare tas inloggningen bort. Uppgifter om vad du har öppnat sparas i 24 månader. Frågor sparas 24 månader efter att de avslutades, längre om de gäller en bestriden faktura. Ett godkännande av en offert sparas så länge offerten och ordern behöver sparas.') }}</p>

        <h2 class="mt-6 text-lg font-semibold">{{ __('Dina rättigheter') }}</h2>
        <p class="mt-1 text-gray-700">{{ __('Under Mina uppgifter kan du se och ladda ner det som sparas om dig, ändra ditt namn och ta bort din inloggning. Du kan också invända mot behandlingen, begära rättelse eller begränsning, och klaga hos Integritetsskyddsmyndigheten (IMY).') }}</p>

        @if($settings->privacy_notice)
            <h2 class="mt-6 text-lg font-semibold">{{ __('Mer från :company', ['company' => $name]) }}</h2>
            <p class="mt-1 whitespace-pre-line text-gray-700">{{ $settings->privacy_notice }}</p>
        @endif
    </article>
@endsection
