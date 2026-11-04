<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            <x-button type="button" wire:click="create" wire:loading.attr="disabled">{{ __('Nytt utskick') }}</x-button>
            <a href="{{ route('crm.segments') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Segment') }}</a>
        </div>
        @if($mailings->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga utskick ännu')">{{ __('Ett utskick är ett nyhetsbrev till ett segment. Bara klick på länkar räknas, aldrig vem som öppnar.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-testid="crm-mailings">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pe-4 font-medium">{{ __('Utskick') }}</th>
                            <th scope="col" class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 pe-4 text-right font-medium">{{ __('Skickade') }}</th>
                            <th scope="col" class="py-2 pe-4 text-right font-medium">{{ __('Klickade') }}</th>
                            <th scope="col" class="py-2 text-right font-medium">{{ __('Avregistrerade') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($mailings as $mailing)
                            <tr wire:key="mailing-{{ $mailing->id }}">
                                <td class="py-3 pe-4">
                                    <a href="{{ route('crm.mailings.show', $mailing) }}" class="font-medium text-gray-900 hover:text-accent-700">{{ $mailing->name }}</a>
                                    <span class="block text-gray-600">{{ $mailing->segment?->name ?? __('Inget segment') }}</span>
                                </td>
                                <td class="py-3 pe-4">{{ $mailing->status->label() }}@if($mailing->scheduled_at && $mailing->status === App\Modules\Crm\Domain\MailingStatus::Scheduled)<span class="block text-gray-600">{{ $mailing->scheduled_at->setTimezone('Europe/Stockholm')->translatedFormat('j M Y H:i') }}</span>@endif</td>
                                <td class="py-3 pe-4 text-right tabular-nums">{{ $mailing->sent_count }} / {{ $mailing->recipient_count }}</td>
                                <td class="py-3 pe-4 text-right tabular-nums">{{ $mailing->unique_clicks }}</td>
                                <td class="py-3 text-right tabular-nums">{{ $mailing->unsubscribed_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $mailings->links() }}</div>
        @endif
    </x-ui.card>
</div>
