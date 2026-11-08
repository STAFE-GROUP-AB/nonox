@extends('portal::portal.layout', ['settings' => $brand, 'company' => $company, 'title' => __('Inbjudan till kundportalen')])

@php($input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900')

@section('content')
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Välkommen till :company:s kundportal', ['company' => $company->legal_name ?: $company->name]) }}</h1>
        <p class="mt-2 text-gray-700">{{ __('För att vara säkra på att det är du skickar vi en kod till :email. Länken ensam räcker inte för att logga in.', ['email' => $email]) }}</p>

        @if(! $sent)
            <form method="POST" action="{{ route('portal.invitation.send', ['token' => $token]) }}" class="mt-6" data-once>
                @csrf
                <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Skicka kod') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('portal.invitation.accept', ['token' => $token]) }}" class="mt-6 space-y-4" data-once>
                @csrf
                <div>
                    <label for="code" class="block text-sm font-medium">{{ __('Kod') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required class="{{ $input }} tracking-widest tabular-nums" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                    @error('code')<p id="code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Öppna kundportalen') }}</button>
            </form>
            <form method="POST" action="{{ route('portal.invitation.send', ['token' => $token]) }}" class="mt-3" data-once>
                @csrf
                <button type="submit" class="text-sm underline underline-offset-2">{{ __('Skicka en ny kod') }}</button>
            </form>
        @endif

        <p class="mt-6 text-sm text-gray-600">{{ __('Kundportalen är frivillig. :company är personuppgiftsansvarig för dina uppgifter i den.', ['company' => $company->legal_name ?: $company->name]) }}
            <a href="{{ route('portal.privacy', ['portalKey' => $brand->portal_key]) }}" class="underline underline-offset-2">{{ __('Så hanteras dina personuppgifter') }}</a></p>
    </div>
@endsection
