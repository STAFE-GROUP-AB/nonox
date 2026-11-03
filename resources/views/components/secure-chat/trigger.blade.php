{{-- Opens the encrypted chat (components/secure-chat/panel, ADR-045). Shows a dot while a chat is open and the number of unread messages. --}}
@props(['label' => false])

<button type="button" data-chat-trigger
        x-data="{ active: false, unread: 0, open: false }"
        x-on:chat-state.window="active = $event.detail.active; unread = $event.detail.unread; open = $event.detail.open === true"
        aria-controls="secure-chat-panel" :aria-expanded="open ? 'true' : 'false'"
        x-on:click="window.dispatchEvent(new CustomEvent('chat-open'))"
        :aria-label="unread > 0 ? @js(__('Chatt')) + ', ' + unread + ' ' + @js(__('olästa')) : (active ? @js(__('Chatt, pågår')) : @js(__('Chatt')))"
        {{ $attributes->class('relative inline-flex items-center gap-1.5 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500') }}>
    <svg class="{{ $label ? 'size-5' : 'size-6' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
    @if($label)
        <span>{{ __('Chatt') }}</span>
    @endif
    <span x-show="active && unread === 0" x-cloak class="absolute right-1 top-1 size-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
    <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread" class="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-accent-500 px-1 text-[11px] font-bold leading-5 text-white tabular-nums" aria-hidden="true"></span>
</button>
