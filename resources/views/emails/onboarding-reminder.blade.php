<x-mail::message>
@if($kind === \App\Mail\OnboardingReminderMail::HELP)
# {{ __('Hej :name, behöver du hjälp?', ['name' => $firstName]) }}

{{ __(':company finns i NONOX, men ännu ligger ingen bokföring där. Vill du ha hjälp att flytta in den, eller har du fastnat på något annat? Så här gör du:', ['company' => $companyName]) }}

@if($plan->system === \App\Domain\Waitlist\OnboardingPlan::FORTNOX)
- [{{ __('Flytta från Fortnox') }}]({{ route('fortnox.move') }}) {{ __('– NONOX hämtar bokföringen, registren och underlagen ur ditt Fortnox-konto.') }}
@endif
- [{{ __('Läs in en SIE-fil') }}]({{ route('accounting.sieImports.index') }}) {{ __('– kontoplan, verifikationer och saldon från ditt gamla program.') }}
- [{{ __('Läs in kunder, leverantörer och artiklar') }}]({{ route('import.registers.index') }})
- [{{ __('Guiden Flytta till NONOX') }}]({{ url('/manual/flytta') }}), {{ __('och manualen:') }} [{{ __('Kom igång') }}]({{ url('/manual/kom-igang') }})

{{ __('Det enklaste är att svara på det här mejlet, så hör jag av mig och hjälper dig.') }}
@else
# {{ __('Hej :name, din inloggning väntar', ['name' => $firstName]) }}

{{ __('För ett par dagar sedan släppte jag in dig i NONOX, men vi har inte sett dig logga in än. Skriv din e-postadress på inloggningssidan så mejlar vi en kod. Inga lösenord att komma ihåg.') }}

<x-mail::button :url="$loginUrl">
{{ __('Logga in') }}
</x-mail::button>

{{ __('Första gången lägger du upp :company. Vi har fyllt i det du skrev i anmälan, så det tar bara några minuter.', ['company' => $companyName]) }}

{{ __('Fastnar du eller undrar du över något? Svara på det här mejlet, så kommer svaret till mig.') }}
@endif

{{ __('Med vänliga hälsningar') }}<br>
{{ $signature }}

<x-mail::subcopy>
{{ __('Det här mejlet skickades från :app, :from. Svarar du på mejlet går svaret till :reply.', ['app' => config('app.name'), 'from' => $fromAddress, 'reply' => $replyToAddress]) }}
{{ __('Vi skickar högst två påminnelser.') }} [{{ __('Inga fler påminnelser') }}]({{ $unsubscribeUrl }})
</x-mail::subcopy>
</x-mail::message>
