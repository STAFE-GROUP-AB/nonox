@extends('portal::portal.layout', ['title' => $invoice->title()])

@php
    $key = app(App\Modules\Portal\Support\PortalContext::class)->key();
    $kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format();
    $qty = fn (string $quantity): string => rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
    $open = ! $invoice->isCredit() && $invoice->status->value === 'ISSUED';
    $copy = fn (string $value, string $label): string => '<button type="button" data-copy="'.e($value).'" data-copied="'.e(__('Kopierat')).'" class="ms-2 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-stone-300 hover:bg-stone-50">'.e(__('Kopiera')).'<span class="sr-only"> '.e($label).'</span> <span data-copy-status class="font-normal text-emerald-800"></span></button>';
@endphp

@section('content')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $invoice->title() }}</h1>
            <p class="mt-1 text-gray-700">{{ App\Modules\Portal\Support\PortalDocuments::status($invoice) }}</p>
        </div>
        <div class="text-right">
            <a href="{{ route('portal.invoices.pdf', ['portalKey' => $key, 'number' => $invoice->number]) }}" class="inline-block rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white">{{ __('Ladda ner PDF') }}</a>
            <p class="mt-1 text-xs text-gray-600">{{ $stored ? __('Fakturan som den utfärdades.') : __('Kopia av fakturan, återskapad från fakturans uppgifter.') }}</p>
        </div>
    </div>

    @if($disputed)
        <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-amber-900 ring-1 ring-amber-200">{{ __('Du har bestridit fakturan. Företaget har fått ditt meddelande under Frågor.') }}</p>
    @endif

    <dl class="mt-6 grid gap-x-6 gap-y-3 rounded-2xl bg-white p-5 text-sm ring-1 ring-stone-200 sm:grid-cols-4">
        <div><dt class="text-gray-600">{{ __('Fakturadatum') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->invoice_date->toDateString() }}</dd></div>
        @unless($invoice->isCredit())
            <div><dt class="text-gray-600">{{ __('Förfallodag') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->due_date->toDateString() }}</dd></div>
        @endunless
        <div><dt class="text-gray-600">{{ __('Belopp') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->isForeign() ? $invoice->formatForeign((int) $invoice->foreign_total_minor) : $invoice->total_ore->format() }}</dd></div>
        <div><dt class="text-gray-600">{{ __('Kvar att betala') }}</dt><dd class="font-medium tabular-nums">{{ $open ? $invoice->formatRemaining() : '–' }}</dd></div>
        @if($invoice->credits)
            <div class="sm:col-span-4"><dt class="text-gray-600">{{ __('Krediterar') }}</dt><dd><a class="underline underline-offset-2" href="{{ route('portal.invoices.show', ['portalKey' => $key, 'number' => $invoice->credits->number]) }}">{{ $invoice->credits->title() }}</a></dd></div>
        @endif
    </dl>

    @if($open)
        <section aria-labelledby="betala" class="mt-6 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <h2 id="betala" class="text-lg font-semibold">{{ __('Betala') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="inline text-gray-600">{{ __('Att betala:') }}</dt> <dd class="inline font-semibold tabular-nums">{{ $invoice->formatRemaining() }}</dd>{!! $copy($invoice->isForeign() ? number_format(((int) $invoice->foreignRemaining()) / 100, 2, ',', '') : number_format($invoice->remaining()->ore / 100, 2, ',', ''), __('beloppet')) !!}</div>
                <div><dt class="inline text-gray-600">{{ __('Sista betalningsdag:') }}</dt> <dd class="inline font-medium tabular-nums">{{ $invoice->due_date->toDateString() }}</dd></div>
                @if($invoice->ocr)
                    <div><dt class="inline text-gray-600">{{ __('OCR-nummer:') }}</dt> <dd class="inline font-mono">{{ $invoice->ocr }}</dd>{!! $copy($invoice->ocr, __('OCR-numret')) !!}</div>
                @endif
                @if($payment['bankgiro'])
                    <div><dt class="inline text-gray-600">{{ __('Bankgiro:') }}</dt> <dd class="inline font-mono">{{ $payment['bankgiro'] }}</dd>{!! $copy($payment['bankgiro'], __('bankgironumret')) !!}</div>
                @endif
                @if($payment['plusgiro'])
                    <div><dt class="inline text-gray-600">{{ __('Plusgiro:') }}</dt> <dd class="inline font-mono">{{ $payment['plusgiro'] }}</dd>{!! $copy($payment['plusgiro'], __('plusgironumret')) !!}</div>
                @endif
                @if($payment['iban'])
                    <div><dt class="inline text-gray-600">{{ __('IBAN:') }}</dt> <dd class="inline font-mono">{{ $payment['iban'] }}</dd>{!! $copy($payment['iban'], 'IBAN') !!}</div>
                @endif
                @if($payment['bic'])
                    <div><dt class="inline text-gray-600">{{ __('BIC:') }}</dt> <dd class="inline font-mono">{{ $payment['bic'] }}</dd>{!! $copy($payment['bic'], 'BIC') !!}</div>
                @endif
            </dl>
            <p class="mt-3 text-sm text-gray-600">{{ __('Betalningen syns här när den har kommit in till oss, normalt inom några bankdagar.') }}</p>

            @if($paidNote)
                <p class="mt-4 rounded-lg bg-stone-50 px-4 py-3 text-sm ring-1 ring-stone-200">{{ __('Du har meddelat att den är betald :date. Det är din egen notering: fakturan visas som betald när betalningen har kommit in.', ['date' => $paidNote->paid_on->toDateString()]) }}</p>
            @else
                <form method="POST" action="{{ route('portal.invoices.paid', ['portalKey' => $key, 'number' => $invoice->number]) }}" class="mt-4 flex flex-wrap items-end gap-3" data-once>
                    @csrf
                    <div>
                        <label for="paid_on" class="block text-sm font-medium">{{ __('Betald den') }}</label>
                        <input id="paid_on" name="paid_on" type="date" required value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}" class="mt-1 rounded-lg border-gray-300" @error('paid_on') aria-invalid="true" aria-describedby="paid-error" @enderror>
                    </div>
                    <button type="submit" class="rounded-lg px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Jag har betalat') }}</button>
                    @error('paid_on')<p id="paid-error" class="w-full text-sm text-red-700">{{ $message }}</p>@enderror
                    <p class="w-full text-xs text-gray-600">{{ __('Meddelar företaget att du har betalat. Det bokför ingenting.') }}</p>
                </form>
            @endif
        </section>
    @endif

    <section aria-labelledby="rader" class="mt-6 overflow-x-auto rounded-2xl bg-white p-5 ring-1 ring-stone-200">
        <h2 id="rader" class="text-lg font-semibold">{{ __('Rader') }}</h2>
        <table class="mt-3 w-full min-w-[32rem] text-sm">
            <caption class="sr-only">{{ __('Fakturans rader') }}</caption>
            <thead>
                <tr class="border-b border-stone-200 text-left text-gray-600">
                    <th scope="col" class="py-2 pr-3 font-normal">{{ __('Benämning') }}</th>
                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Antal') }}</th>
                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Moms') }}</th>
                    <th scope="col" class="py-2 text-right font-normal">{{ __('Belopp') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->rows as $row)
                    <tr class="border-b border-stone-100">
                        <td class="py-2 pr-3">{{ $row->description }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $qty((string) $row->quantity) }} {{ $row->unit }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $row->vat_rate / 100 }} %</td>
                        <td class="py-2 text-right tabular-nums">{{ $kr($row->net_ore->ore) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <dl class="mt-4 space-y-1 text-sm">
            @foreach($vat['vat_by_rate'] ?? [] as $rate => $group)
                @if(($group['vat'] ?? 0) !== 0)
                    <div class="flex justify-end gap-4"><dt class="text-gray-600">{{ __('Moms :rate %', ['rate' => $rate / 100]) }}</dt><dd class="w-32 text-right tabular-nums">{{ $kr((int) $group['vat']) }}</dd></div>
                @endif
            @endforeach
            <div class="flex justify-end gap-4 font-semibold"><dt>{{ __('Summa') }}</dt><dd class="w-32 text-right tabular-nums">{{ $invoice->total_ore->format() }}</dd></div>
        </dl>
    </section>

    @if($invoice->payments->isNotEmpty() || $invoice->creditInvoices->isNotEmpty() || $invoice->reminders->isNotEmpty())
        <section aria-labelledby="handelser" class="mt-6 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
            <h2 id="handelser" class="text-lg font-semibold">{{ __('Betalningar, krediteringar och påminnelser') }}</h2>
            <ul class="mt-3 divide-y divide-stone-100 text-sm">
                @foreach($invoice->payments as $payment)
                    <li class="py-2">{{ __('Betalning :date: :amount', ['date' => $payment->paid_on->toDateString(), 'amount' => $payment->amount_ore->format()]) }}</li>
                @endforeach
                @foreach($invoice->creditInvoices as $credit)
                    <li class="py-2"><a class="underline underline-offset-2" href="{{ route('portal.invoices.show', ['portalKey' => $key, 'number' => $credit->number]) }}">{{ $credit->title() }}</a> {{ $credit->invoice_date->toDateString() }}: {{ $credit->total_ore->format() }}</li>
                @endforeach
                @foreach($invoice->reminders as $reminder)
                    <li class="py-2">{{ __('Påminnelse :sequence skickad :date, betala senast :pay_by. Kvar då: :amount', ['sequence' => $reminder->sequence, 'date' => $reminder->sent_on->toDateString(), 'pay_by' => $reminder->pay_by->toDateString(), 'amount' => $reminder->remaining_ore->format()]) }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mt-6 flex flex-wrap gap-3 text-sm">
        <a href="{{ route('portal.questions.create', ['portalKey' => $key, 'faktura' => $invoice->number]) }}" class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Fråga om fakturan') }}</a>
        @if($open && ! $disputed)
            <a href="{{ route('portal.questions.create', ['portalKey' => $key, 'faktura' => $invoice->number, 'bestrid' => 1]) }}" class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Jag bestrider fakturan') }}</a>
        @endif
    </div>
@endsection
