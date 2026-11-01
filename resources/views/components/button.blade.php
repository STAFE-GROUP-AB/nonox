<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 rounded-xl border border-transparent bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 active:bg-accent-800 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
