{{-- The way in for someone without an account: the queue (ADR-038), or self-registration when HQ has opened it (ADR-044). --}}
<p class="muted small" style="margin-top:18px" data-join-waitlist>
    @if(App\Support\Instance\InstanceSettings::current()->selfRegistrationOpen())
        Inget konto än? Skriv din e-postadress, så skapar vi ett när du har skrivit in koden.
    @elseif(config('app.landing.enabled') && App\Support\Instance\InstanceSettings::current()->waitlistOpen())
        Inte godkänd än? <a href="{{ route('waitlist') }}">Ställ dig i kö</a>. NONOX är i öppen beta fram till lanseringen av version 1.0 den 1 januari 2027.
    @elseif(config('app.landing.enabled'))
        Kön till betan är stängd just nu. Har ett företag bjudit in dig loggar du in med adressen inbjudan gick till.
    @else
        Har du inget konto? Be den som administrerar ert företag i NONOX att bjuda in dig.
    @endif
</p>
