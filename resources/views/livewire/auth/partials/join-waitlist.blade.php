{{-- No self-registration (ADR-038): the way in for someone without an account. --}}
<p class="muted small" style="margin-top:18px" data-join-waitlist>
    @if(config('app.landing.enabled'))
        Inte godkänd än? <a href="{{ route('waitlist') }}">Ställ dig i kö</a>. NONOX är i öppen beta fram till lanseringen av version 1.0 den 1 januari 2027.
    @else
        Har du inget konto? Be den som administrerar ert företag i NONOX att bjuda in dig.
    @endif
</p>
