@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($kr = fn (?int $ore): string => $ore === null ? '–' : App\Support\Money\Money::ofOre($ore)->format())
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-end gap-4">
            <x-ui.field for="ongoing-date" :label="__('Balansdag')"><input id="ongoing-date" type="date" wire:model.live="date" class="{{ $input }}"></x-ui.field>
            <p class="text-sm text-gray-500">{{ __('Metod för fastprisuppdrag: :method.', ['method' => $method->label()]) }} <a href="{{ route('workOrders.settings') }}" class="text-accent-700 hover:underline">{{ __('Ändra') }}</a></p>
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ __('Förslaget följer K2 och K3 för uppdrag på löpande räkning och till fast pris. Kontona :accounts och vilka förslag som gäller för varje regelverk är inte slutligt kontrollerade. För K1 visas bara siffrorna.', ['accounts' => implode(', ', $accounts)]) }}</p>
    </x-ui.card>

    @if($error)
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $error }}</p>
    @endif

    @if($lines === [])
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga pågående arbeten på balansdagen')">{{ __('Öppna arbetsorder med upparbetat eller fakturerat belopp visas här.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        @foreach($lines as $line)
            <x-ui.card :title="__('Arbetsorder :number', ['number' => $line['work_order']->number]).' · '.$line['work_order']->title" wire:key="ongoing-{{ $line['work_order']->id }}">
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                    @if($line['kind'] === 'FIXED')
                        <div><dt class="text-gray-500">{{ __('Fast pris med ÄTA') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['contract']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Varav ÄTA') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['change_orders']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Kostnader på projektet') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['costs']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Färdigställandegrad') }}</dt><dd class="tabular-nums">{{ $line['figures']['degree_bp'] === null ? '–' : number_format($line['figures']['degree_bp'] / 100, 1, ',', ' ').' %' }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Upparbetad intäkt') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['earned']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Fakturerat') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['invoiced']) }}</dd></div>
                        <x-ui.field :for="'estimated-'.$line['work_order']->id" :label="__('Beräknad total kostnad')" class="sm:col-span-3">
                            <input id="estimated-{{ $line['work_order']->id }}" wire:model.live.debounce.500ms="estimated.{{ $line['work_order']->id }}" inputmode="decimal" class="{{ $input }} sm:w-56 text-right tabular-nums">
                        </x-ui.field>
                    @else
                        <div><dt class="text-gray-500">{{ __('Upparbetat') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['performed']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Upparbetat, ej fakturerat') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['unbilled']) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Fakturerat i förskott') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['ahead']) }}</dd></div>
                    @endif
                </dl>
                @if($line['rows'] !== [])
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3 font-medium">{{ __('Konto') }}</th><th class="py-1 pr-3 text-right font-medium">{{ __('Debet') }}</th><th class="py-1 text-right font-medium">{{ __('Kredit') }}</th></tr></thead>
                            <tbody>
                                @foreach($line['rows'] as $row)
                                    <tr><td class="py-1 pr-3">{{ $row['account'] }}</td><td class="py-1 pr-3 text-right tabular-nums">{{ $row['debit_ore'] ? $kr($row['debit_ore']) : '' }}</td><td class="py-1 text-right tabular-nums">{{ $row['credit_ore'] ? $kr($row['credit_ore']) : '' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if($line['note'])<p class="mt-3 text-sm text-gray-600">{{ $line['note'] }}</p>@endif
            </x-ui.card>
        @endforeach

        <x-ui.card>
            <x-button type="button" wire:click="propose" wire:loading.attr="disabled" wire:confirm="{{ __('Skapa två verifikationsutkast, justeringen på balansdagen och återföringen dagen efter? Ingenting bokförs förrän du bokför utkasten.') }}">{{ __('Skapa verifikationsutkast') }}</x-button>
        </x-ui.card>
    @endif
</div>
