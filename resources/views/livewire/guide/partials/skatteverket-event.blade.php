{{-- One of Skatteverket's customer events, with its heading, description and extended fields in full (resources/views/livewire/guide/skatteverket-events.blade.php). --}}
@php($awaits = in_array($event->id, $awaitingIds, true))
<li @class([
    'rounded-2xl px-4 py-3 ring-1',
    'bg-accent-50 ring-accent-200' => $awaits,
    'bg-white ring-stone-200' => ! $awaits,
]) wire:key="skatteverket-event-{{ $event->id }}" data-skatteverket-event="{{ $event->id }}">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <p class="min-w-0 flex-1 font-semibold text-ink" @if($event->language !== '') lang="{{ $event->language }}" @endif>{{ $event->title }}</p>
        <span class="text-xs tabular-nums text-gray-600">{{ $time($event->occurred_at) }}</span>
    </div>
    <p class="mt-1 whitespace-pre-line text-sm text-gray-700" @if($event->language !== '') lang="{{ $event->language }}" @endif>{{ $event->description }}</p>
    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600">
        @if($awaits)
            <x-ui.badge tone="accent">{{ __('Väntar på företaget') }}</x-ui.badge>
        @elseif($event->handled_at !== null)
            <span>{{ __('Markerad som hanterad :date', ['date' => $event->handled_at->setTimezone(config('app.timezone'))->format('Y-m-d')]) }}</span>
        @elseif($event->requires_action && ! $event->case_closed)
            <span>{{ __('En senare händelse i samma ärende har kommit.') }}</span>
        @endif
        @if($event->case_closed)
            <span>{{ __('Skatteverket ser ärendet som klart.') }}</span>
        @endif
        @if($event->due_date !== null)
            <span>{{ __('Förfallodag :date', ['date' => $event->due_date->toDateString()]) }}</span>
        @endif
        @if($event->producer !== '')
            <span>{{ __('Från :producer', ['producer' => $event->producer]) }}</span>
        @endif
        @if($event->skatteslag !== null)
            <span>{{ __('Skatteslag: :value', ['value' => $event->skatteslag]) }}</span>
        @endif
        @foreach($event->kundamne ?? [] as $topic)
            <span>{{ __('Ämne: :value', ['value' => $topic]) }}</span>
        @endforeach
        @if($event->korrespondens_referens !== null)
            <span>{{ __('Referens: :value', ['value' => $event->korrespondens_referens]) }}</span>
        @endif
        @if($event->kopplings_id !== null)
            <span>{{ __('Hör ihop med: :value', ['value' => $event->kopplings_id]) }}</span>
        @endif
    </div>
    @if($event->notice !== null)
        <p class="mt-2 text-xs text-gray-600" @if($event->language !== '') lang="{{ $event->language }}" @endif>{{ __('Avisering från Skatteverket:') }} {{ $event->notice }}</p>
    @endif
    @if(($event->links ?? []) !== [] || $event->requires_action)
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            @foreach($event->links ?? [] as $link)
                @if(App\Support\Skatteverket\SkatteverketLink::isSkatteverket($link['url']))
                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-700 underline hover:text-accent-800">{{ $link['text'] ?? __('Öppna hos Skatteverket') }}<span class="sr-only"> {{ __('(öppnas i en ny flik)') }}</span></a>
                @else
                    {{-- Not a skatteverket.se address: shown in full as text, never as a link. --}}
                    <span class="break-all text-xs text-gray-600" data-skatteverket-plain-link>{{ __('Adress i händelsen (inte till skatteverket.se, visas inte som länk):') }} {{ $link['text'] !== null ? $link['text'].' – ' : '' }}{{ $link['url'] }}</span>
                @endif
            @endforeach
            @if($event->requires_action)
                @if($event->handled_at === null)
                    <button type="button" wire:click="markHandled({{ $event->id }})" wire:loading.attr="disabled" class="font-semibold text-gray-700 underline hover:text-ink">{{ __('Markera som hanterad') }}</button>
                @else
                    <button type="button" wire:click="markUnhandled({{ $event->id }})" wire:loading.attr="disabled" class="text-gray-600 underline hover:text-ink">{{ __('Markera som inte hanterad') }}</button>
                @endif
            @endif
        </div>
    @endif
</li>
