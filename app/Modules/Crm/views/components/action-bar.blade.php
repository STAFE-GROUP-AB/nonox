{{--
    The phone's action bar: fixed to the bottom of the screen above the home
    indicator (safe-area inset), with the four things done most often. Hidden
    from sm up, where the header's quick actions are in view. The page
    leaves room for it (pb-28 sm:pb-0 on its wrapper).
--}}
<nav {{ $attributes->merge(['class' => 'fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 px-2 pt-2 shadow-[0_-4px_16px_rgba(23,23,26,0.06)] backdrop-blur sm:hidden']) }} style="padding-bottom: calc(0.5rem + env(safe-area-inset-bottom, 0px))" aria-label="{{ __('Snabbval') }}">
    <div class="mx-auto flex max-w-md items-start justify-around">
        {{ $slot }}
    </div>
</nav>
