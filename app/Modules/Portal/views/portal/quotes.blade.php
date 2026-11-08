@extends('portal::portal.layout', ['title' => __('Offerter och ordrar')])

@php($key = app(App\Modules\Portal\Support\PortalContext::class)->key())

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Offerter och ordrar') }}</h1>

    @if($documents->isEmpty())
        <div class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-stone-200">
            <p class="font-semibold">{{ __('Inga offerter eller ordrar ännu') }}</p>
            <p class="mt-1 text-gray-700">{{ __('Här visas offerter och ordrar som företaget har skickat till er.') }}</p>
        </div>
    @else
        <ul class="mt-6 divide-y divide-stone-100 rounded-2xl bg-white ring-1 ring-stone-200">
            @foreach($documents as $document)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <a href="{{ route('portal.quotes.show', ['portalKey' => $key, 'document' => $document->id]) }}" class="font-semibold underline underline-offset-2">{{ $document->title() }}</a>
                    <span class="text-sm text-gray-700 tabular-nums">{{ $document->document_date->toDateString() }} · {{ $document->total_ore->format() }} · {{ $document->status->label() }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $documents->links() }}</div>
    @endif
@endsection
