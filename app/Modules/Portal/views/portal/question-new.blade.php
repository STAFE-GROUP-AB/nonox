@extends('portal::portal.layout', ['title' => $dispute ? __('Bestrid fakturan') : __('Ny fråga')])

@php
    $key = app(App\Modules\Portal\Support\PortalContext::class)->key();
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
    $subject = $invoice ? ($dispute ? __('Jag bestrider :title', ['title' => $invoice->title()]) : __('Fråga om :title', ['title' => $invoice->title()])) : ($quote ? __('Fråga om :title', ['title' => $quote->title()]) : '');
@endphp

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $dispute ? __('Bestrid fakturan') : __('Ny fråga') }}</h1>
    @if($dispute)
        <p class="mt-2 text-gray-700">{{ __('Skriv varför du inte vill betala fakturan, helt eller delvis. Företaget ser att fakturan är bestridd och svarar här.') }}</p>
    @endif

    <form method="POST" action="{{ route('portal.questions.store', ['portalKey' => $key, 'faktura' => $invoice?->number, 'offert' => $quote?->id]) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-4 rounded-2xl bg-white p-5 ring-1 ring-stone-200" data-once>
        @csrf
        <input type="hidden" name="kind" value="{{ $dispute ? 'DISPUTE' : 'QUESTION' }}">
        <div>
            <label for="subject" class="block text-sm font-medium">{{ __('Rubrik') }}</label>
            <input id="subject" name="subject" required maxlength="200" value="{{ old('subject', $subject) }}" class="{{ $input }}" @error('subject') aria-invalid="true" aria-describedby="subject-error" @enderror>
            @error('subject')<p id="subject-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="body" class="block text-sm font-medium">{{ __('Meddelande') }}</label>
            <textarea id="body" name="body" rows="6" required maxlength="4000" class="{{ $input }}" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ old('body') }}</textarea>
            @error('body')<p id="body-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
        @include('portal::portal.partials.files')
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Skicka') }}</button>
    </form>
@endsection
