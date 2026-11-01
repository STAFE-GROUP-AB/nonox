{{-- The bell: unread notices ($unreadNotices, counted once in navigation-menu), opens Notiser. --}}
<a href="{{ route('notifications.index') }}" class="relative rounded-lg p-2 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 {{ $bellClass }}"
   aria-label="{{ $unreadNotices > 0 ? trans_choice('{1} Notiser, :count oläst|[2,*] Notiser, :count olästa', $unreadNotices, ['count' => $unreadNotices]) : __('Notiser') }}">
    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
    @if($unreadNotices > 0)
        <span class="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-accent-500 px-1 text-[11px] font-bold leading-5 text-white tabular-nums">{{ $unreadNotices > 99 ? '99+' : $unreadNotices }}</span>
    @endif
</a>
