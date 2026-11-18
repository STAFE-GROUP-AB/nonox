@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (?int $ore): string => $ore === null ? '–' : App\Support\Money\Money::ofOre($ore)->format();
    $pct = fn (?int $bp): string => $bp === null ? '–' : number_format($bp / 100, 1, ',', ' ')."\u{00A0}%";
@endphp
<div class="space-y-6" data-project-wip>
    <x-ui.card>
        <div class="flex flex-wrap items-end gap-4">
            <x-ui.field for="wip-date" :label="__('Balansdag')">
                <input id="wip-date" type="date" wire:model.live="date" class="{{ $input }} mt-1">
                <x-input-error for="date" class="mt-1" />
            </x-ui.field>
            @if($canSetMethod)
                <form wire:submit="saveMethod" class="flex flex-wrap items-end gap-3">
                    <x-ui.field for="wip-method" :label="__('Metod för fastprisuppdrag')">
                        <select id="wip-method" wire:model="method" class="{{ $input }} mt-1">
                            @foreach($methods as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="method" class="mt-1" />
                    </x-ui.field>
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara metod') }}</x-button>
                </form>
            @elseif($wip !== null)
                <p class="text-sm text-gray-500">{{ __('Metod för fastprisuppdrag: :method.', ['method' => $wip['method']->label()]) }}</p>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ __('Metoden gäller alla fastprisuppdrag i företaget, både projekt och arbetsorder. Förslaget följer K2 och K3 för uppdrag till fast pris. Kontona :accounts och vilka förslag som gäller för varje regelverk är inte slutligt kontrollerade. För K1 visas bara siffrorna.', ['accounts' => implode(', ', $accounts)]) }}</p>
    </x-ui.card>

    @if($dateError)
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $dateError }}</p>
    @elseif($wip !== null)
        @if($wip['with_work_orders'] > 0)
            <p class="rounded-lg bg-stone-100 px-4 py-3 text-sm text-gray-700">
                {{ trans_choice('{1} Ett projekt har arbetsorder och räknas under Arbetsorder → Pågående arbeten.|[2,*] :count projekt har arbetsorder och räknas under Arbetsorder → Pågående arbeten.', $wip['with_work_orders'], ['count' => $wip['with_work_orders']]) }}
                @if($workOrdersOn)
                    <a href="{{ route('workOrders.ongoing', ['datum' => $date]) }}" class="text-accent-700 hover:underline">{{ __('Öppna Pågående arbeten') }}</a>
                @endif
            </p>
        @endif

        @if($wip['lines'] === [])
            <x-ui.card>
                <x-ui.empty-state :title="__('Inga pågående projekt på balansdagen')">{{ __('Projekt till fast pris eller på löpande räkning, utan arbetsorder, med kostnader eller fakturor till och med balansdagen visas här.') }}</x-ui.empty-state>
            </x-ui.card>
        @else
            @foreach($wip['lines'] as $line)
                <x-ui.card :title="$line['project']->label()" wire:key="wip-{{ $line['project']->id }}">
                    <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                        @if($line['kind'] === App\Domain\Projects\ProjectWip::FIXED)
                            <div><dt class="text-gray-500">{{ __('Kontraktssumma') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['contract']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Beräknad total kostnad') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['estimated']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Kostnader på projektet') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['costs']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Färdigställandegrad') }}</dt><dd class="tabular-nums">{{ $pct($line['figures']['degree_bp']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Upparbetad intäkt') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['earned']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Fakturerat') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['invoiced']) }}</dd></div>
                        @else
                            <div><dt class="text-gray-500">{{ __('Kostnader på projektet') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['costs']) }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Fakturerat') }}</dt><dd class="tabular-nums">{{ $kr($line['figures']['invoiced']) }}</dd></div>
                        @endif
                    </dl>
                    @if($line['rows'] !== [])
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pr-3 font-medium">{{ __('Konto') }}</th><th scope="col" class="py-1 pr-3 text-right font-medium">{{ __('Debet') }}</th><th scope="col" class="py-1 text-right font-medium">{{ __('Kredit') }}</th></tr></thead>
                                <tbody>
                                    @foreach($line['rows'] as $row)
                                        <tr><td class="py-1 pr-3 tabular-nums">{{ $row['account'] }}</td><td class="py-1 pr-3 text-right tabular-nums">{{ $row['debit_ore'] ? $kr($row['debit_ore']) : '' }}</td><td class="py-1 text-right tabular-nums">{{ $row['credit_ore'] ? $kr($row['credit_ore']) : '' }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if($line['note'])<p class="mt-3 text-sm text-gray-600">{{ $line['note'] }}</p>@endif
                    <p class="mt-3 text-sm"><a href="{{ route('projects.show', $line['project']->id) }}" class="text-accent-700 hover:underline">{{ __('Öppna projektet') }}</a></p>
                </x-ui.card>
            @endforeach

            @if(collect($wip['lines'])->contains(fn (array $line): bool => $line['rows'] !== []))
                <x-ui.card>
                    <x-button type="button" wire:click="propose" wire:loading.attr="disabled" wire:confirm="{{ __('Skapa två verifikationsutkast, justeringen på balansdagen och återföringen dagen efter? Ingenting bokförs förrän du bokför utkasten.') }}">{{ __('Skapa verifikationsutkast') }}</x-button>
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
