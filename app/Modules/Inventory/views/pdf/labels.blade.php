<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Etiketter') }}</title>
    <style>
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #000; }
        .page { position: relative; width: {{ $pageWidth }}mm; height: {{ $pageHeight }}mm; overflow: hidden; page-break-after: always; }
        .page.last { page-break-after: auto; }
        .label { position: absolute; width: {{ $labelWidth }}mm; height: {{ $labelHeight }}mm; overflow: hidden; }
        .guide { border: 0.1mm dashed #9ca3af; }
    </style>
</head>
<body>
    @foreach($pages as $page)
        <div class="page{{ $loop->last ? ' last' : '' }}">
            @foreach($page as [$x, $y, $boxes])
                <div class="label{{ $guides ? ' guide' : '' }}" style="left:{{ $x }}mm;top:{{ $y }}mm">
                    @include('inventory::labels.boxes', ['boxes' => $boxes])
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
