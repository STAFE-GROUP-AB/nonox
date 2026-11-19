<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('signing.head', ['pageTitle' => __('Dina filer från signeringen')])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <main id="main" class="mx-auto max-w-xl px-4 pb-16 pt-10 sm:pt-16">
        <div class="rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8" style="border-top: 6px solid {{ $color }};">
            @if($companyName)
                <p class="text-sm font-semibold text-gray-700">{{ $companyName }}</p>
            @endif
            @if($state === 'files')
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Dokumentet är signerat: :title', ['title' => $title]) }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Filerna var för stora för att skickas med mejlet. Ladda ner dem här och spara dem tillsammans.') }}</p>
                <ul class="mt-5 divide-y divide-stone-200 rounded-xl ring-1 ring-stone-200">
                    @foreach($files as $index => $file)
                        <li class="flex items-center justify-between gap-3 p-3">
                            <span class="min-w-0 break-words text-sm font-medium">{{ $file['name'] }}@if($file['size']) <span class="font-normal text-gray-600">({{ $file['size'] < 1048576 ? max(1, (int) round($file['size'] / 1024)).' kB' : number_format($file['size'] / 1048576, 1, app()->getLocale() === 'en' ? '.' : ',', app()->getLocale() === 'en' ? ',' : ' ').' MB' }})</span>@endif</span>
                            <a href="{{ route('signing.copy.file', ['token' => $token, 'file' => $index + 1]) }}" class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-white" style="background: {{ $color }};" download>{{ __('Ladda ner') }}</a>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-sm text-gray-600">{{ __('Länken fungerar till och med :date.', ['date' => $expires->format('Y-m-d')]) }}</p>
            @elseif($state === 'code')
                @include('signing.copy-code')
            @elseif($state === 'expired')
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Länken har gått ut') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Be avsändaren skicka en ny länk.') }}</p>
            @elseif($state === 'revoked')
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Länken är stängd') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Avsändaren har stängt länken. Be om en ny länk.') }}</p>
            @else
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Länken kan inte användas') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Länken är fel eller finns inte längre.') }}</p>
            @endif
        </div>
        <p class="mt-8 text-center text-xs text-gray-600">NONOX</p>
    </main>
</body>
</html>
