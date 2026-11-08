@extends('portal::portal.layout', ['title' => __('Frågor')])

@php($key = app(App\Modules\Portal\Support\PortalContext::class)->key())

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Frågor') }}</h1>
        <a href="{{ route('portal.questions.create', ['portalKey' => $key]) }}" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Ny fråga') }}</a>
    </div>

    @if($threads->isEmpty())
        <div class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-stone-200">
            <p class="font-semibold">{{ __('Inga frågor ännu') }}</p>
            <p class="mt-1 text-gray-700">{{ __('Undrar du något om en faktura eller en offert? Skriv till företaget här, så sparas frågan och svaret.') }}</p>
        </div>
    @else
        <ul class="mt-6 divide-y divide-stone-100 rounded-2xl bg-white ring-1 ring-stone-200">
            @foreach($threads as $thread)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <a href="{{ route('portal.questions.show', ['portalKey' => $key, 'thread' => $thread->id]) }}" class="font-semibold underline underline-offset-2">{{ $thread->subject }}</a>
                    <span class="text-sm text-gray-700">
                        {{ $thread->kind->label() }} ·
                        {{ $thread->isOpen() ? ($thread->answered ? __('Besvarad') : __('Väntar på svar')) : __('Avslutad') }} ·
                        <span class="tabular-nums">{{ $thread->last_message_at->setTimezone(config('app.timezone'))->format('Y-m-d') }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $threads->links() }}</div>
    @endif
@endsection
