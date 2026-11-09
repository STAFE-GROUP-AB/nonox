@extends('portal::portal.layout', ['title' => __('Översikt')])

@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
@php($key = app(App\Modules\Portal\Support\PortalContext::class)->key())

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Översikt') }}</h1>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <dt class="text-sm text-gray-600">{{ __('Att betala') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $kr($openAmount) }}</dd>
        </div>
        <div class="rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <dt class="text-sm text-gray-600">{{ __('Varav förfallet') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums {{ $overdueAmount > 0 ? 'text-red-700' : '' }}">{{ $kr($overdueAmount) }}</dd>
        </div>
        <div class="rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <dt class="text-sm text-gray-600">{{ __('Nästa förfallodag') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $nextDue?->toDateString() ?? '–' }}</dd>
        </div>
        <div class="rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <dt class="text-sm text-gray-600">{{ __('Öppna offerter') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $openQuotes }}</dd>
        </div>
    </dl>

    @if($unread > 0)
        <p class="mt-6 rounded-2xl bg-white p-4 ring-1 ring-stone-200">{{ __('Nya svar att läsa: :count.', ['count' => $unread]) }} <a href="{{ route('portal.questions', ['portalKey' => $key]) }}" class="font-semibold underline underline-offset-2">{{ __('Till Frågor') }}</a></p>
    @endif

    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('portal.invoices', ['portalKey' => $key]) }}" class="rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Se fakturor') }}</a>
        <a href="{{ route('portal.questions.create', ['portalKey' => $key]) }}" class="rounded-lg px-4 py-3 font-semibold ring-1 ring-stone-300 bg-white">{{ __('Ställ en fråga') }}</a>
    </div>
@endsection
