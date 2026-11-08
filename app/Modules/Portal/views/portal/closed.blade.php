@extends('portal::portal.layout', ['settings' => null, 'company' => null, 'title' => __('Kundportal')])

@section('content')
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Länken kan inte användas') }}</h1>
        <p class="mt-3 text-gray-700">{{ $message }}</p>
        <p class="mt-4"><a href="{{ route('portal.login') }}" class="font-semibold underline underline-offset-2">{{ __('Till inloggningen') }}</a></p>
    </div>
@endsection
