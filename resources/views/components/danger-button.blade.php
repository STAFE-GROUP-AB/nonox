<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl sm:min-h-0 border border-transparent bg-red-700 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-red-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
