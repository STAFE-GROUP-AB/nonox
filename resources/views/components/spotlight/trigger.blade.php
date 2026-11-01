{{-- Opens the spotlight (components/spotlight/dialog). Looks like a search field; the shortcut is shown for the user's keyboard. --}}
<button type="button" x-data x-on:click="window.dispatchEvent(new CustomEvent('spotlight-open'))" aria-haspopup="dialog" aria-keyshortcuts="Meta+K Control+K /"
        {{ $attributes->class('group flex w-full max-w-md items-center gap-3 rounded-xl bg-white px-3.5 py-2 text-left text-sm text-stone-500 shadow-xs ring-1 ring-stone-200 transition hover:ring-stone-300 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500') }}>
    <svg class="size-4 shrink-0 text-stone-400 group-hover:text-stone-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
    <span class="flex-1 truncate">{{ __('Sök eller gå till …') }}</span>
    <kbd class="rounded-md border border-stone-200 bg-stone-50 px-1.5 py-0.5 font-sans text-[11px] font-medium text-stone-500" x-text="/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘K' : 'Ctrl K'">⌘K</kbd>
</button>
