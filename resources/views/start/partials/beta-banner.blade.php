{{-- The open beta notice on every public page (ADR-038). In the flow above the header: it scrolls away and never covers content. --}}
@if(config('app.landing.enabled'))
<div data-beta-banner style="background:#15171C;color:#F7F5F0;font-size:14px;line-height:1.45;text-align:center;padding:9px clamp(16px,4vw,40px)">
  <p style="margin:0"><strong style="font-weight:750">Öppen beta</strong> – skarp lansering av version 1.0 den 1 januari 2027. @if(! request()->routeIs('waitlist'))<a href="{{ App\Support\Landing\SignupPath::url() }}" style="color:#FFB8A6;font-weight:700;text-underline-offset:3px">{{ App\Support\Landing\SignupPath::label() }}</a>@endif</p>
</div>
@endif
