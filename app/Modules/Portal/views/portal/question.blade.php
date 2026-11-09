@extends('portal::portal.layout', ['title' => $thread->subject])

@php
    $key = app(App\Modules\Portal\Support\PortalContext::class)->key();
    $company = app(App\Modules\Portal\Support\PortalContext::class)->company();
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
@endphp

@section('content')
    <p class="text-sm"><a href="{{ route('portal.questions', ['portalKey' => $key]) }}" class="underline underline-offset-2">{{ __('Alla frågor') }}</a></p>
    <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">{{ $thread->subject }}</h1>
    <p class="mt-1 text-gray-700">{{ $thread->kind->label() }} · {{ $thread->isOpen() ? __('Öppen') : __('Avslutad') }}</p>

    <ol class="mt-6 space-y-4">
        @foreach($thread->messages as $message)
            <li class="rounded-2xl p-4 ring-1 {{ $message->fromContact() ? 'bg-white ring-stone-200' : 'bg-stone-50 ring-stone-300' }}">
                <p class="text-sm font-semibold">
                    {{ $message->fromContact() ? ($message->contact_id === null ? __('Borttagen kontakt') : ($message->contact_id === app(App\Modules\Portal\Support\PortalContext::class)->contact()->id ? __('Du') : ($message->contact?->identity?->name ?? __('Borttagen kontakt')))) : ($company->legal_name ?: $company->name) }}
                    <span class="font-normal text-gray-600 tabular-nums">{{ $message->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                </p>
                <p class="mt-2 whitespace-pre-line">{{ $message->body }}</p>
                @if($message->attachments->isNotEmpty())
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach($message->attachments as $file)
                            <li><a href="{{ route('portal.questions.attachment', ['portalKey' => $key, 'thread' => $thread->id, 'attachment' => $file->id]) }}" class="underline underline-offset-2">{{ $file->original_name }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ol>

    <form method="POST" action="{{ route('portal.questions.reply', ['portalKey' => $key, 'thread' => $thread->id]) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-4 rounded-2xl bg-white p-5 ring-1 ring-stone-200" data-once>
        @csrf
        <div>
            <label for="body" class="block text-sm font-medium">{{ $thread->isOpen() ? __('Skriv ett svar') : __('Skriv igen för att öppna frågan') }}</label>
            <textarea id="body" name="body" rows="4" required maxlength="4000" class="{{ $input }}" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ old('body') }}</textarea>
            @error('body')<p id="body-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
        @include('portal::portal.partials.files')
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Skicka') }}</button>
    </form>
@endsection
