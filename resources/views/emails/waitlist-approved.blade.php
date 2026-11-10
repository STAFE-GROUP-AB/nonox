<x-mail::message>
# {{ __('Hej :name, du är inne!', ['name' => $firstName]) }}

{{ __('Jag har gått igenom din anmälan och släppt in dig i NONOX. Du kan logga in nu: skriv din e-postadress, :email, på inloggningssidan så mejlar vi en kod. Inga lösenord att komma ihåg.', ['email' => $email]) }}

<x-mail::button :url="$loginUrl">
{{ __('Logga in') }}
</x-mail::button>

**{{ __('Så kommer :company igång', ['company' => $companyName]) }}**

1. **{{ __('Skapa företaget.') }}** {{ __('Första gången du loggar in kommer du till Kom igång. Vi har fyllt i företagets namn, organisationsnummer och bransch från din anmälan, så du behöver bara kontrollera dem.') }} [{{ __('Så skapar du ett företag') }}]({{ url('/manual/kom-igang/skapa-ett-foretag') }})
@if($plan->system === \App\Domain\Waitlist\OnboardingPlan::FORTNOX)
2. **{{ __('Flytta från Fortnox.') }}** {{ __('Du kan låta NONOX hämta bokföringen, kunderna, leverantörerna och underlagen direkt ur ditt Fortnox-konto, år för år.') }} [{{ __('Flytta från Fortnox') }}]({{ route('fortnox.move') }}). {{ __('Vill du hellre göra det själv med SIE-filer finns det en steg-för-steg-artikel:') }} [{{ __('Från Fortnox') }}]({{ url('/manual/flytta/fran-fortnox') }})
@elseif($plan->system === \App\Domain\Waitlist\OnboardingPlan::OTHER_PROGRAM)
2. **{{ __('Läs in bokföringen från ditt nuvarande program.') }}** {{ __('Exportera en SIE-fil ur programmet och läs in den, så följer kontoplan, verifikationer och saldon med.') }} [{{ __('Läs in en SIE-fil') }}]({{ route('accounting.sieImports.index') }}). {{ __('Kunder, leverantörer och artiklar läser du in från kalkylblad eller filer ur programmet.') }} [{{ __('Läs in uppgifter') }}]({{ route('import.registers.index') }}). {{ __('Hela vägen beskrivs här:') }} [{{ __('Från andra program') }}]({{ url('/manual/flytta/fran-andra-program') }})
@else
2. **{{ __('Börja från början.') }}** {{ __('Du har inget program att flytta från, så du lägger upp kunder och artiklar själv och bokför direkt. Kom igång-listan på översikten tar dig steg för steg: adress och bankgiro, och sedan din första faktura.') }}
@endif
@if($plan->hasEmployees)
3. **{{ __('Lön.') }}** {{ __('Ni har anställda, så slå på modulen Lön under Inställningar för företaget, fliken Moduler. Har ni löner i ett annat program finns hjälp att flytta lönedata:') }} [{{ __('Lön') }}]({{ url('/manual/lon') }}), [{{ __('Flytta lönedata') }}]({{ url('/manual/lon/flytt-av-lonedata') }})
@endif

@if(count($moduleLines) > 0)
**{{ $industryPhrase ? __('Moduler som brukar passa :industry', ['industry' => $industryPhrase]) : __('Moduler som kan passa ditt företag') }}**

{{ __('Utöver bokföring och fakturering kan de här modulerna passa. Alla moduler ingår utan extra kostnad, och du slår på dem under Inställningar för företaget, fliken Moduler.') }}

@foreach($moduleLines as $line)
- {!! $line !!}
@endforeach

@endif
{{ __('NONOX är i öppen beta. Skarp lansering av version 1.0 sker den :date, och det du lägger in under betan ligger kvar.', ['date' => $launchDate]) }}

{{ __('Fastnar du eller undrar du över något? Svara på det här mejlet, så kommer svaret till mig.') }}

{{ __('Med vänliga hälsningar') }}<br>
{{ $signature }}

<x-mail::subcopy>
{{ __('Det här mejlet skickades från :app, :from. Svarar du på mejlet går svaret till :reply.', ['app' => config('app.name'), 'from' => $fromAddress, 'reply' => $replyToAddress]) }}
</x-mail::subcopy>
</x-mail::message>
