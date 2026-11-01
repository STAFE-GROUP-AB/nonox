<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl sm:min-h-0 border border-stone-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-800 shadow-xs transition hover:bg-stone-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
