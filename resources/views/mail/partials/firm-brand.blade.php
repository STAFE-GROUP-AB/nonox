{{-- The accounting firm's logo and name at the top of an email to a client's people (ADR-030). One line of HTML: Markdown mail ends an HTML block at a blank line. --}}
@if(($mailBrand ?? null) instanceof App\Support\Branding\PresentedBrand && $mailBrand->viaFirm())
@php
    $brandColor = $mailBrand->brand()->color;
    $brandText = $mailBrand->brand()->accentText();
    $brandLogo = isset($message) && ! $message instanceof Illuminate\Mail\TextMessage ? $mailBrand->logoForMail() : null;
    $brandCid = $brandLogo !== null ? $message->embedData($brandLogo['data'], $brandLogo['name'], $brandLogo['mime']) : null;
@endphp
@if(isset($message) && $message instanceof Illuminate\Mail\TextMessage)
{{ $mailBrand->title() }}
@else
<table class="firm-brand" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-top: 4px solid {{ $brandColor }}; margin: 0 0 24px;"><tr><td style="padding: 16px 0 0;">@if($brandCid)<img src="{{ $brandCid }}" alt="{{ $mailBrand->firmName }}" style="display: block; max-height: 48px; max-width: 200px; margin: 0 0 8px;">@endif<p style="margin: 0; font-size: 13px; font-weight: 600; color: {{ $brandText }};">{{ $mailBrand->title() }}</p></td></tr></table>
@endif

@endif
