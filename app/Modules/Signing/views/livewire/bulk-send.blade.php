@php
    $statusLabel = fn (string $status): string => match ($status) {
        'PREVIEW' => __('Förhandsgranskning'),
        'RUNNING' => __('Pågår'),
        'COMPLETED' => __('Klart'),
        default => __('Avbrutet'),
    };
    $rowLabel = fn (string $status): string => match ($status) {
        'INVALID' => __('Skickas inte'),
        'PENDING' => __('Väntar'),
        'PROCESSING' => __('Skickas nu'),
        'SENT' => __('Skickad'),
        'FAILED' => __('Misslyckades'),
        default => __('Hoppades över'),
    };
    $rowTone = fn (string $status): string => match ($status) {
        'SENT' => 'success',
        'INVALID', 'FAILED' => 'danger',
        'PENDING', 'PROCESSING' => 'accent',
        default => 'neutral',
    };
    $done = $run->sent_count + $run->failed_count;
    $roleLabels = $template?->roles->pluck('label', 'key') ?? collect();
@endphp

<div class="space-y-6" @if($run->status === 'RUNNING') wire:poll.5s @endif>
    <x-ui.card :title="$template?->name ?? __('Mallen är borttagen')" :description="__('Startat av :name', ['name' => $run->creator?->name ?? '–'])">
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5" data-testid="bulk-counts">
            <div><dt class="text-gray-600">{{ __('Status') }}</dt><dd class="mt-1 font-semibold text-gray-900">{{ $statusLabel($run->status) }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Rader i filen') }}</dt><dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $run->total_rows }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Kan skickas') }}</dt><dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $run->valid_rows }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Skickas inte') }}</dt><dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $run->invalid_rows }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Skickade') }}</dt><dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $run->sent_count }}</dd></div>
        </dl>

        @if($run->status === 'RUNNING')
            <div class="mt-4" role="status">
                <label for="bulk-progress" class="text-sm text-gray-700">{{ __(':done av :total rader klara', ['done' => $done, 'total' => $run->valid_rows]) }}</label>
                <progress id="bulk-progress" class="mt-1 block h-3 w-full" max="{{ max(1, $run->valid_rows) }}" value="{{ $done }}"></progress>
                @if($waiting)
                    <p class="mt-2 text-sm text-amber-900">{{ __('Väntar på företagets e-postkvot (högst :limit e-postmeddelanden i timmen). Utskicket fortsätter av sig självt.', ['limit' => $limit]) }}</p>
                @endif
            </div>
        @endif

        @if($run->status === 'PREVIEW')
            <div class="mt-4 space-y-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-900" data-testid="bulk-warning">
                <p class="font-semibold">{{ __('Inget har skickats än.') }}</p>
                <p>{{ __('Kontrollera raderna nedan. När du väljer Skicka skapas :count signeringar, var och en med sitt eget förlopp, och undertecknarna i första steget får sin länk.', ['count' => $run->valid_rows]) }}</p>
                @if($run->invalid_rows > 0)
                    <p>{{ trans_choice('{1} En rad har ett fel och skickas inte. Rätta den i filen och läs in den i ett eget massutskick.|[2,*] :count rader har fel och skickas inte. Rätta dem i filen och läs in dem i ett eget massutskick.', $run->invalid_rows, ['count' => $run->invalid_rows]) }}</p>
                @endif
                @if($mails > $remaining)
                    <p data-testid="bulk-quota">{{ __('E-postkvoten räcker just nu till :remaining av de :mails e-postmeddelandena (högst :limit i timmen). Resten skickas när kvoten släpper, vilket tar omkring :hours timmar till.', ['remaining' => $remaining, 'mails' => $mails, 'limit' => $limit, 'hours' => max(1, $hours)]) }}</p>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                @if($run->valid_rows > 0)
                    <x-button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" wire:confirm="{{ __('Skicka :count signeringar? Undertecknarna får sin länk och det går inte att ta tillbaka e-postmeddelandena.', ['count' => $run->valid_rows]) }}">{{ __('Skicka :count signeringar', ['count' => $run->valid_rows]) }}</x-button>
                @endif
                <button type="button" wire:click="cancel" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 text-sm font-medium text-ink ring-1 ring-stone-200 hover:bg-gray-50">{{ __('Ta bort förhandsgranskningen') }}</button>
            </div>
        @elseif($run->status === 'RUNNING')
            <div class="mt-4">
                <button type="button" wire:click="cancel" wire:confirm="{{ __('Avbryta massutskicket? Det som redan skickats ligger kvar. Raderna som återstår skickas inte.') }}" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 text-sm font-medium text-ink ring-1 ring-stone-200 hover:bg-gray-50">{{ __('Avbryt massutskicket') }}</button>
            </div>
        @endif
    </x-ui.card>

    @if($preview->isNotEmpty())
        <x-ui.card :title="__('De första raderna')" :description="__('Så här blir de första raderna. Adresserna visas bara här, före utskicket.')">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm" data-testid="bulk-preview">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Rad') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Roller och undertecknare') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preview as $row)
                            <tr wire:key="preview-{{ $row->id }}" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-3 tabular-nums">{{ $row->row_number }}</td>
                                <td class="py-3">
                                    <ul class="space-y-1">
                                        @foreach((array) ($row->payload['signers'] ?? []) as $signer)
                                            <li><span class="font-medium">{{ $roleLabels[$signer['role_key']] ?? $signer['role_key'] }}:</span> {{ $signer['name'] !== '' ? $signer['name'].', ' : '' }}{{ $signer['email'] }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :title="$run->status === 'PREVIEW' ? __('Kontroll av raderna') : __('Resultat per rad')">
        <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
            @foreach(['alla' => __('Alla rader'), 'problem' => __('Bara de som inte skickas'), 'skickade' => __('Bara skickade')] as $key => $label)
                <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $show === $key, 'bg-white text-ink ring-1 ring-stone-200' => $show !== $key]) aria-pressed="{{ $show === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if($rows->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga rader att visa')">{{ __('Inga rader motsvarar det valda urvalet.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm" data-testid="bulk-rows">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Rad') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Kommentar') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="row-{{ $row->id }}" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-3 tabular-nums">{{ $row->row_number }}</td>
                                <td class="py-3 pr-3"><x-ui.badge :tone="$rowTone($row->status)">{{ $rowLabel($row->status) }}</x-ui.badge></td>
                                <td class="py-3">
                                    @if($row->envelope_id)
                                        <a href="{{ route('signing.envelope', $row->envelope_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna signeringen') }}</a>
                                    @else
                                        {{ $row->reason ?? '' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    </x-ui.card>
</div>
