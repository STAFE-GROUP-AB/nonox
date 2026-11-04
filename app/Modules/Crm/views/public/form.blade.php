{{-- A company's web form (ADR-048): no session, no cookie, nothing loaded from elsewhere. The company's colour, the fields it chose, its own consent text and privacy policy. --}}
@php
    $brand = App\Support\Branding\Brand::for($company);
    $companyName = $company->legal_name ?: $company->name;
    $value = fn (string $field): string => (string) ($values[$field] ?? '');
    $error = fn (string $field): ?string => $errors[$field][0] ?? null;
    $labels = ['phone' => __('Telefon'), 'company' => __('Företag'), 'org_number' => __('Organisationsnummer'), 'message' => __('Meddelande')];
@endphp
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $form->name }} · {{ $companyName }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: #ffffff; color: #1f2933; font: 16px/1.5 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; }
        main { max-width: 560px; margin: 0 auto; }
        h1 { margin: 0 0 4px; font-size: 1.375rem; color: #15171c; }
        .lead { margin: 0 0 20px; color: #52606d; font-size: .9375rem; }
        .field { margin: 0 0 16px; }
        label { display: block; margin: 0 0 4px; font-weight: 600; font-size: .9375rem; }
        input[type=text], input[type=email], input[type=tel], textarea { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #9aa5b1; border-radius: 8px; font: inherit; color: inherit; background: #fff; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus, button:focus { outline: 3px solid {{ $brand->color }}; outline-offset: 2px; }
        .error { margin: 4px 0 0; color: #b42318; font-size: .875rem; font-weight: 600; }
        .summary { margin: 0 0 20px; padding: 12px 16px; border: 2px solid #b42318; border-radius: 8px; color: #b42318; }
        .consent { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
        .consent input { width: 22px; height: 22px; margin: 2px 0 0; flex: none; }
        .consent a { color: #15171c; }
        button { min-height: 48px; padding: 0 24px; border: 0; border-radius: 10px; background: {{ $brand->color }}; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .optional { font-weight: 400; color: #52606d; }
        .foot { margin: 24px 0 0; font-size: .8125rem; color: #52606d; }
    </style>
</head>
<body>
<main>
    @if($done)
        <h1>{{ __('Tack!') }}</h1>
        <p class="lead" role="status">{{ $form->thank_you ?: __('Vi har tagit emot dina uppgifter och hör av oss.') }}</p>
    @else
        <h1>{{ $form->name }}</h1>
        <p class="lead">{{ $companyName }}</p>

        @if($errors !== [])
            <div class="summary" role="alert" tabindex="-1" id="fel">
                <strong>{{ __('Något behöver rättas:') }}</strong>
                <ul>
                    @foreach($errors as $field => $messages)
                        <li><a href="#f-{{ $field }}">{{ $messages[0] }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('crm.forms.submit', $form->token) }}" novalidate>
            <input type="hidden" name="t" value="{{ $stamp }}">
            <div class="hp" aria-hidden="true">
                <label for="f-webbplats">{{ __('Lämna tomt') }}</label>
                <input type="text" id="f-webbplats" name="webbplats" tabindex="-1" autocomplete="off">
            </div>

            <div class="field">
                <label for="f-name">{{ __('Namn') }}</label>
                <input type="text" id="f-name" name="name" value="{{ $value('name') }}" autocomplete="name" required @if($error('name')) aria-invalid="true" aria-describedby="e-name" @endif>
                @if($error('name'))<p class="error" id="e-name">{{ $error('name') }}</p>@endif
            </div>
            <div class="field">
                <label for="f-email">{{ __('E-post') }}</label>
                <input type="email" id="f-email" name="email" value="{{ $value('email') }}" autocomplete="email" required @if($error('email')) aria-invalid="true" aria-describedby="e-email" @endif>
                @if($error('email'))<p class="error" id="e-email">{{ $error('email') }}</p>@endif
            </div>

            @foreach(App\Modules\Crm\Models\Form::OPTIONAL_FIELDS as $field)
                @php($state = $form->fieldState($field))
                @continue($state === 'off')
                <div class="field">
                    <label for="f-{{ $field }}">{{ $labels[$field] }}@if($state === 'optional') <span class="optional">({{ __('frivilligt') }})</span>@endif</label>
                    @if($field === 'message')
                        <textarea id="f-message" name="message" @if($state === 'required') required @endif @if($error('message')) aria-invalid="true" aria-describedby="e-message" @endif>{{ $value('message') }}</textarea>
                    @else
                        <input type="{{ $field === 'phone' ? 'tel' : 'text' }}" id="f-{{ $field }}" name="{{ $field }}" value="{{ $value($field) }}" @if($field === 'phone') autocomplete="tel" @elseif($field === 'company') autocomplete="organization" @endif @if($state === 'required') required @endif @if($error($field)) aria-invalid="true" aria-describedby="e-{{ $field }}" @endif>
                    @endif
                    @if($error($field))<p class="error" id="e-{{ $field }}">{{ $error($field) }}</p>@endif
                </div>
            @endforeach

            <div class="field">
                <label class="consent" for="f-consent">
                    <input type="checkbox" id="f-consent" name="consent" value="1" @checked($value('consent') !== '') required @if($error('consent')) aria-invalid="true" aria-describedby="e-consent" @endif>
                    <span>{{ $form->consent_text }} <a href="{{ $form->privacy_url }}" target="_blank" rel="noopener noreferrer">{{ __('Läs integritetspolicyn') }}</a></span>
                </label>
                @if($error('consent'))<p class="error" id="e-consent">{{ $error('consent') }}</p>@endif
            </div>

            <button type="submit">{{ __('Skicka') }}</button>
        </form>
        <p class="foot">{{ __(':company tar emot uppgifterna och ansvarar för dem.', ['company' => $companyName]) }}</p>
    @endif
</main>
<script nonce="{{ $nonce }}">
    (function () {
        var summary = document.getElementById('fel');
        if (summary) { summary.focus(); }
        function report() {
            if (window.parent !== window) {
                window.parent.postMessage({ nonoxForm: '{{ $form->token }}', height: document.documentElement.scrollHeight }, '*');
            }
        }
        window.addEventListener('load', report);
        window.addEventListener('resize', report);
        report();
    })();
</script>
</body>
</html>
