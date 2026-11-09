<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Kallelse') }}</title>
    <style>
        @page { margin: 22mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #17171a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        p { margin: 0 0 6px; }
        ol { padding-left: 18px; }
        li { margin-bottom: 4px; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <h1>{{ __('Kallelse till :meeting i :company', ['meeting' => mb_strtolower($meeting->kind->label()), 'company' => $company->legal_name ?: $company->name]) }}</h1>
    <p>{{ __('Aktieägarna i :company, :org, kallas till :meeting.', ['company' => $company->legal_name ?: $company->name, 'org' => $company->org_number, 'meeting' => mb_strtolower($meeting->kind->label())]) }}</p>
    <p><strong>{{ __('Tid') }}:</strong> {{ $meeting->meeting_date->toDateString() }}@if($meeting->start_time) {{ __('kl.') }} {{ $meeting->start_time }}@endif</p>
    <p><strong>{{ __('Plats') }}:</strong> {{ $meeting->place ?: '–' }}@if($meeting->digital) ({{ __('det går att delta digitalt') }})@endif</p>
    <p>{{ __('Rätt att delta har den som är införd i aktieboken på stämmodagen.') }}</p>

    <p><strong>{{ __('Förslag till dagordning') }}</strong></p>
    <ol>
        @foreach($items as $item)
            <li>{{ $item->title }}@if($item->proposal)<br><span class="muted">{{ __('Förslag') }}: {{ $item->proposal }}</span>@endif</li>
        @endforeach
    </ol>

    <p class="muted">{{ __('Utfärdad av styrelsen.') }}</p>
</body>
</html>
