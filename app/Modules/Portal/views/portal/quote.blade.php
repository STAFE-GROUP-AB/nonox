@extends('portal::portal.layout', ['title' => $document->title()])

@php
    $key = app(App\Modules\Portal\Support\PortalContext::class)->key();
    $kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format();
    $qty = fn (string $quantity): string => rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
@endphp

@section('content')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $document->title() }}</h1>
            <p class="mt-1 text-gray-700">{{ $document->status->label() }} · {{ $document->document_date->toDateString() }}@if($document->until_date) · {{ __('Giltig till :date', ['date' => $document->until_date->toDateString()]) }}@endif</p>
        </div>
        <a href="{{ route('portal.quotes.pdf', ['portalKey' => $key, 'document' => $document->id]) }}" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Ladda ner PDF') }}</a>
    </div>

    <section aria-labelledby="rader" class="mt-6 overflow-x-auto rounded-2xl bg-white p-5 ring-1 ring-stone-200">
        <h2 id="rader" class="text-lg font-semibold">{{ __('Rader') }}</h2>
        <table class="mt-3 w-full min-w-[32rem] text-sm">
            <caption class="sr-only">{{ __('Rader') }}</caption>
            <thead>
                <tr class="border-b border-stone-200 text-left text-gray-600">
                    <th scope="col" class="py-2 pr-3 font-normal">{{ __('Benämning') }}</th>
                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Antal') }}</th>
                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('À-pris') }}</th>
                    <th scope="col" class="py-2 text-right font-normal">{{ __('Belopp') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->rows as $row)
                    <tr class="border-b border-stone-100">
                        <td class="py-2 pr-3">{{ $row->description }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $qty((string) $row->quantity) }} {{ $row->unit }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $kr($row->unit_price_ore->ore) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $kr($row->net_ore->ore) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-4 text-right font-semibold tabular-nums">{{ __('Summa :amount', ['amount' => $document->total_ore->format()]) }}</p>
        @if($document->notes)
            <p class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ $document->notes }}</p>
        @endif
    </section>

    @if($decision)
        <p class="mt-6 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            {{ $decision->status->value === 'SIGNED'
                ? __('Godkänd :date av :name.', ['date' => $decision->signed_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'name' => $decision->signer_name])
                : __('Avböjd :date.', ['date' => $decision->declined_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}
        </p>
    @elseif($canAccept)
        <section aria-labelledby="godkann" class="mt-6 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <h2 id="godkann" class="text-lg font-semibold">{{ __('Godkänn offerten') }}</h2>
            <p class="mt-1 text-sm text-gray-700">{{ __('Läs igenom offerten och PDF:en. När du godkänner sparas ditt namn, tiden och exakt vilken offert du godkände, och du får en kopia med e-post.') }}</p>
            @error('document')<p class="mt-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900 ring-1 ring-red-200">{{ $message }}</p>@enderror
            <form method="POST" action="{{ route('portal.quotes.decide', ['portalKey' => $key, 'document' => $document->id]) }}" class="mt-4 space-y-4" data-once>
                @csrf
                <input type="hidden" name="decision" value="accept">
                <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
                <div>
                    <label for="name" class="block text-sm font-medium">{{ __('Ditt namn') }}</label>
                    <input id="name" name="name" required autocomplete="name" value="{{ old('name', app(App\Modules\Portal\Support\PortalContext::class)->identity()->name) }}" class="{{ $input }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<p id="name-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="accept" value="1" class="mt-0.5 rounded border-gray-400" @error('accept') aria-invalid="true" aria-describedby="accept-error" @enderror>
                    <span>{{ $acceptance }}</span>
                </label>
                @error('accept')<p id="accept-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Godkänn offerten') }}</button>
            </form>
            <details class="mt-6">
                <summary class="cursor-pointer text-sm font-semibold">{{ __('Avböj offerten') }}</summary>
                <form method="POST" action="{{ route('portal.quotes.decide', ['portalKey' => $key, 'document' => $document->id]) }}" class="mt-3 space-y-3" data-once data-confirm="{{ __('Vill du avböja offerten? Företaget får besked.') }}">
                    @csrf
                    <input type="hidden" name="decision" value="decline">
                    <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
                    <label for="reason" class="block text-sm font-medium">{{ __('Varför? (frivilligt)') }}</label>
                    <textarea id="reason" name="reason" rows="3" maxlength="1000" class="{{ $input }}"></textarea>
                    <button type="submit" class="rounded-lg px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Avböj') }}</button>
                </form>
            </details>
        </section>
    @endif

    <p class="mt-6 text-sm"><a href="{{ route('portal.questions.create', ['portalKey' => $key, 'offert' => $document->id]) }}" class="font-semibold underline underline-offset-2">{{ __('Fråga om offerten') }}</a></p>
@endsection
