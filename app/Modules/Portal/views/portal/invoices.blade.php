@extends('portal::portal.layout', ['title' => __('Fakturor')])

@php($key = app(App\Modules\Portal\Support\PortalContext::class)->key())

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Fakturor') }}</h1>

    @if($invoices->isEmpty())
        <div class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-stone-200">
            <p class="font-semibold">{{ __('Inga fakturor ännu') }}</p>
            <p class="mt-1 text-gray-700">{{ __('Här visas fakturorna och kreditfakturorna från företaget när de har skickats.') }}</p>
        </div>
    @else
        <div class="mt-6 overflow-x-auto rounded-2xl bg-white ring-1 ring-stone-200">
            <table class="w-full min-w-[40rem] text-sm">
                <caption class="sr-only">{{ __('Fakturor') }}</caption>
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Faktura') }}</th>
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Fakturadatum') }}</th>
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Förfallodag') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-normal">{{ __('Belopp') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-normal">{{ __('Kvar att betala') }}</th>
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr class="border-b border-stone-100">
                            <td class="px-4 py-3"><a href="{{ route('portal.invoices.show', ['portalKey' => $key, 'number' => $invoice->number]) }}" class="font-semibold underline underline-offset-2">{{ $invoice->title() }}</a></td>
                            <td class="px-4 py-3 tabular-nums">{{ $invoice->invoice_date->toDateString() }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $invoice->isCredit() ? '–' : $invoice->due_date->toDateString() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $invoice->isForeign() ? $invoice->formatForeign((int) $invoice->foreign_total_minor) : $invoice->total_ore->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $invoice->status->value === 'ISSUED' ? $invoice->formatRemaining() : '–' }}</td>
                            <td class="px-4 py-3">{{ App\Modules\Portal\Support\PortalDocuments::status($invoice) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
@endsection
