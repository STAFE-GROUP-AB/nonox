@extends('portal::portal.layout', ['settings' => null, 'company' => null, 'title' => __('Mina uppgifter')])

@php($input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900')

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Mina uppgifter') }}</h1>
    <p class="mt-2 text-gray-700">{{ __('Det här sparas om dig som användare av kundportalen. Fakturorna och offerterna är företagets och påverkas inte av det du gör här.') }}</p>

    <section aria-labelledby="namn" class="mt-6 max-w-xl rounded-2xl bg-white p-5 ring-1 ring-stone-200">
        <h2 id="namn" class="text-lg font-semibold">{{ __('Namn och e-post') }}</h2>
        <form method="POST" action="{{ route('portal.mine.rename') }}" class="mt-3 space-y-3" data-once>
            @csrf
            <div>
                <label for="name" class="block text-sm font-medium">{{ __('Namn') }}</label>
                <input id="name" name="name" required maxlength="120" value="{{ old('name', $identity->name) }}" class="{{ $input }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name')<p id="name-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <p class="text-sm text-gray-700">{{ __('E-post: :email. Den används för att logga in; vill du byta den, be företaget ändra den.', ['email' => $identity->email]) }}</p>
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 font-semibold text-white">{{ __('Spara') }}</button>
        </form>
    </section>

    <section aria-labelledby="portaler" class="mt-6 max-w-xl rounded-2xl bg-white p-5 ring-1 ring-stone-200">
        <h2 id="portaler" class="text-lg font-semibold">{{ __('Dina kundportaler') }}</h2>
        @if($portals->isEmpty())
            <p class="mt-2 text-gray-700">{{ __('Du har ingen kundportal att öppna just nu.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-stone-100">
                @foreach($portals as $portal)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <a href="{{ route('portal.home', ['portalKey' => $portal['key']]) }}" class="font-medium underline underline-offset-2">{{ $portal['company']->legal_name ?: $portal['company']->name }} ({{ $portal['contact']->customer?->name }})</a>
                        <form method="POST" action="{{ route('portal.mine.remove') }}" data-confirm="{{ __('Vill du ta bort din inloggning till den här kundportalen? Det går inte att ångra; du behöver en ny inbjudan för att komma tillbaka.') }}">
                            @csrf
                            <input type="hidden" name="contact" value="{{ $portal['contact']->id }}">
                            <input type="hidden" name="confirm" value="1">
                            <button type="submit" class="text-sm font-medium text-red-700 underline underline-offset-2">{{ __('Ta bort min inloggning här') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="utdrag" class="mt-6 max-w-xl rounded-2xl bg-white p-5 ring-1 ring-stone-200">
        <h2 id="utdrag" class="text-lg font-semibold">{{ __('Dina uppgifter som fil') }}</h2>
        <p class="mt-1 text-sm text-gray-700">{{ __('Allt som sparas om dig i kundportalerna: namn, e-post, portaler, dina meddelanden och när du har loggat in och vad du har öppnat.') }}</p>
        <a href="{{ route('portal.mine.export') }}" class="mt-3 inline-block rounded-lg px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Ladda ner (JSON)') }}</a>
    </section>

    <section aria-labelledby="ta-bort" class="mt-6 max-w-xl rounded-2xl bg-white p-5 ring-1 ring-red-200">
        <h2 id="ta-bort" class="text-lg font-semibold">{{ __('Ta bort min inloggning') }}</h2>
        <p class="mt-1 text-sm text-gray-700">{{ __('Din inloggning tas bort i alla kundportaler. Dina meddelanden finns kvar hos företagen, med avsändaren ”Borttagen kontakt”, så länge de behöver dem.') }}</p>
        <form method="POST" action="{{ route('portal.mine.remove') }}" class="mt-3 space-y-3" data-confirm="{{ __('Vill du ta bort din inloggning i alla kundportaler? Det går inte att ångra.') }}">
            @csrf
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-gray-400">
                <span>{{ __('Jag förstår att jag inte kan logga in igen utan en ny inbjudan.') }}</span>
            </label>
            @error('confirm')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button type="submit" class="rounded-lg bg-red-700 px-4 py-2 font-semibold text-white">{{ __('Ta bort min inloggning') }}</button>
        </form>
    </section>
@endsection
