@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = config('app.timezone');
@endphp

<div class="space-y-6">
    @unless($moduleOn)
        <p class="rounded-xl border border-stone-200 bg-white px-4 py-3 text-sm text-gray-700" data-testid="signing-archive-note">{{ __('Modulen Signering är avstängd. Här finns de egna dokument som blev signerade, med signeringsbevis.') }}</p>
    @endunless

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canSend)
                <a href="{{ route('signing.new') }}" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-gray-800">{{ __('Skicka dokument') }}</a>
            @endif
            <input type="search" class="{{ $input }} w-full sm:max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på rubrik') }}" aria-label="{{ __('Sök signeringar') }}">
            <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
                @foreach($filters as $key => $label)
                    <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $show === $key, 'bg-white text-ink ring-1 ring-stone-200' => $show !== $key]) aria-pressed="{{ $show === $key ? 'true' : 'false' }}">{{ __($label) }}</button>
                @endforeach
            </div>
        </div>

        @if($envelopes->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga signeringar att visa')">
                {{ $search !== '' || $show !== 'alla' ? __('Ingen signering matchar sökningen eller filtret.') : __('Här visas allt som skickas för signering. Välj Skicka dokument för att ladda upp en PDF och skicka den till de som ska signera.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm" data-testid="signing-envelopes">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Dokument') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Typ') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Signerat') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Skickad') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Sista dag') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($envelopes as $envelope)
                            @php($status = $envelope->effectiveStatus($now))
                            <tr wire:key="envelope-{{ $envelope->id }}" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-3">
                                    <a href="{{ route('signing.envelope', $envelope->id) }}" class="font-semibold text-gray-900 hover:text-accent-700">{{ $envelope->title }}</a>
                                    <span class="block text-gray-600">{{ __('Skickad av :name', ['name' => $envelope->creator?->name ?? '–']) }}</span>
                                </td>
                                <td class="py-3 pr-3">
                                    {{ App\Modules\Signing\Support\EnvelopeVisibility::typeLabel($envelope->document_type) }}
                                    @if($envelope->confidential)
                                        <x-ui.badge tone="warning" class="ms-1">{{ __('Konfidentiell') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap py-3 pr-3 tabular-nums">{{ __(':signed av :count', ['signed' => $envelope->signed_count, 'count' => $envelope->signers_count]) }}</td>
                                <td class="whitespace-nowrap py-3 pr-3 tabular-nums">{{ $envelope->sent_at?->setTimezone($zone)->format('Y-m-d') ?? '–' }}</td>
                                <td class="whitespace-nowrap py-3 pr-3 tabular-nums">{{ $envelope->deadline_at?->setTimezone($zone)->format('Y-m-d') ?? '–' }}</td>
                                <td class="py-3"><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $envelopes->links() }}</div>
        @endif
    </x-ui.card>
</div>
