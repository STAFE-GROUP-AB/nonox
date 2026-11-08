@extends('portal::portal.layout', ['settings' => $brand, 'title' => __('Logga in i kundportalen')])

@php($input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900')

@section('content')
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Logga in i kundportalen') }}</h1>

        @if($step === 'email')
            <p class="mt-2 text-gray-700">{{ __('Skriv den e-postadress som företaget bjöd in. Vi skickar en kod dit, du behöver inget lösenord.') }}</p>
            <form method="POST" action="{{ route('portal.send') }}" class="mt-6 space-y-4" data-once>
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium">{{ __('E-postadress') }}</label>
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" class="{{ $input }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p id="email-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Skicka kod') }}</button>
            </form>
        @else
            <p class="mt-2 text-gray-700">{{ __('Skriv koden från mejlet till :email. Den gäller i 10 minuter.', ['email' => $email]) }}</p>
            <form method="POST" action="{{ route('portal.verify') }}" class="mt-6 space-y-4" data-once>
                @csrf
                <div>
                    <label for="code" class="block text-sm font-medium">{{ __('Kod') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required class="{{ $input }} tracking-widest tabular-nums" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                    @error('code')<p id="code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Logga in') }}</button>
            </form>
            <p class="mt-4 text-sm"><a href="{{ route('portal.login') }}" class="underline underline-offset-2">{{ __('Använd en annan adress eller be om en ny kod') }}</a></p>
        @endif
    </div>
@endsection
