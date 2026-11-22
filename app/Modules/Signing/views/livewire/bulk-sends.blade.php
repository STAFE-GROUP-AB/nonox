@php
    $statusLabel = fn (string $status): string => match ($status) {
        'PREVIEW' => __('Förhandsgranskning'),
        'RUNNING' => __('Pågår'),
        'COMPLETED' => __('Klart'),
        default => __('Avbrutet'),
    };
    $statusTone = fn (string $status): string => match ($status) {
        'PREVIEW' => 'warning',
        'RUNNING' => 'accent',
        'COMPLETED' => 'success',
        default => 'neutral',
    };
@endphp

<div class="space-y-6">
    <x-ui.card :title="__('Nytt massutskick')" :description="__('Välj en mall för ett eget dokument och en fil (CSV eller Excel) med en rad per signering. Filen sparas inte: raderna läses in en gång och filen tas bort.')">
        @if($templates->isEmpty())
            <x-ui.empty-state :title="__('Inga mallar att skicka från')">
                {{ __('Massutskick utgår från en mall för ett eget dokument. Skapa en under Mallar först.') }}
                <a href="{{ route('signing.templates') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Mallar') }}</a>
            </x-ui.empty-state>
        @else
            <form wire:submit="read" class="space-y-4" novalidate>
                <x-ui.field for="bulk_template" :label="__('Mall')" error="templateId">
                    <select id="bulk_template" wire:model.live="templateId" class="mt-1 block min-h-11 w-full max-w-md rounded-lg border-gray-300 text-sm">
                        <option value="">{{ __('Välj mall') }}</option>
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($chosen)
                    <p class="text-sm text-gray-600">
                        {{ __('Filen ska ha en kolumn med e-post för varje roll i mallen, och gärna kolumner för namn och språk.') }}
                        <a href="{{ route('signing.bulk-sends.example', $chosen->id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner exempelfil för mallen') }}</a>
                    </p>
                @endif
                <x-ui.field for="bulk_file" :label="__('Fil med en rad per signering')" :hint="__('Högst :max rader per massutskick, och högst 5 MB.', ['max' => \App\Modules\Signing\Models\SigningBulkSend::MAX_ROWS])" error="file">
                    <input id="bulk_file" type="file" accept=".csv,.tsv,.txt,.xlsx,text/csv" wire:model="file" class="mt-1 block text-sm">
                </x-ui.field>
                <x-button type="submit" wire:loading.attr="disabled" wire:target="file,read">{{ __('Läs in och granska') }}</x-button>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Massutskick')">
        @if($runs->isEmpty())
            <x-ui.empty-state :title="__('Inga massutskick än')">
                {{ __('Här visas varje massutskick med hur många signeringar som skickades och vilka rader som inte kunde skickas.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm" data-testid="signing-bulk-sends">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Mall') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Rader') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Skickade') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Ej skickade') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Startat av') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $run)
                            <tr wire:key="bulk-{{ $run->id }}" class="border-b border-gray-100">
                                <td class="py-3 pr-3"><a href="{{ route('signing.bulk-send', $run->id) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $run->template?->name ?? __('Mallen är borttagen') }}</a><span class="block text-gray-600">{{ $run->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span></td>
                                <td class="py-3 pr-3"><x-ui.badge :tone="$statusTone($run->status)">{{ $statusLabel($run->status) }}</x-ui.badge></td>
                                <td class="py-3 pr-3 text-right tabular-nums">{{ $run->total_rows }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums">{{ $run->sent_count }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums">{{ $run->status === 'PREVIEW' ? '–' : $run->total_rows - $run->sent_count }}</td>
                                <td class="py-3">{{ $run->creator?->name ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $runs->links() }}</div>
        @endif
    </x-ui.card>
</div>
