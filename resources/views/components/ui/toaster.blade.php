{{--
    Shows feedback toasts. Self-styled so it works both in the app and on the
    start pages (which do not load the built CSS). Listens for the browser
    event "toast" that Livewire's $this->toast(...) dispatches, and shows
    toasts flashed with App\Support\Toast::flash() on page load.
--}}
<div
    x-data="{
        toasts: [],
        next: 0,
        paused: false,
        add(message, type = 'success') {
            const id = this.next++;
            this.toasts.push({ id, message, type, visible: true });
            this.expire(id, type === 'error' ? 8000 : 5000);
        },
        {{-- A toast stays while the pointer or keyboard focus is on it (WCAG 2.2.1). --}}
        expire(id, after) {
            setTimeout(() => this.paused ? this.expire(id, 2000) : this.remove(id), after);
        },
        remove(id) {
            const toast = this.toasts.find(t => t.id === id);
            if (toast) toast.visible = false;
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 250);
        },
    }"
    x-init="@foreach(App\Support\Toast::pending() as $toast) add({{ Js::from($toast['message']) }}, {{ Js::from($toast['type']) }}); @endforeach"
    x-on:toast.window="add($event.detail.message ?? $event.detail[0]?.message, $event.detail.type ?? $event.detail[0]?.type)"
    x-on:mouseenter="paused = true"
    x-on:mouseleave="paused = false"
    x-on:focusin="paused = true"
    x-on:focusout="paused = false"
    class="nx-toaster"
    role="status"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div class="nx-toast" :class="{ 'nx-toast--error': toast.type === 'error', 'nx-toast--hidden': ! toast.visible }">
            <span class="nx-toast__icon" aria-hidden="true" x-text="toast.type === 'error' ? '!' : '✓'"></span>
            <p class="nx-toast__message" x-text="toast.message"></p>
            <button type="button" class="nx-toast__close" x-on:click="remove(toast.id)" aria-label="Stäng">×</button>
        </div>
    </template>
</div>

<style>
    .nx-toaster {
        position: fixed; z-index: 100; right: 1.25rem; bottom: calc(1.25rem + env(safe-area-inset-bottom, 0px));
        display: flex; flex-direction: column; gap: .5rem; width: min(24rem, calc(100vw - 2.5rem)); pointer-events: none;
    }
    .nx-toast {
        pointer-events: auto; display: flex; align-items: flex-start; gap: .75rem;
        padding: .85rem 1rem; border-radius: 10px;
        background: #1f1d1a; color: #f7f5f0; box-shadow: 0 10px 30px rgba(31, 29, 26, .25);
        font: 500 .95rem/1.4 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        transition: opacity .25s ease, transform .25s ease;
        animation: nx-toast-in .25s ease;
    }
    .nx-toast--hidden { opacity: 0; transform: translateY(.5rem); }
    .nx-toast__icon {
        flex: none; display: inline-flex; align-items: center; justify-content: center;
        width: 1.4rem; height: 1.4rem; border-radius: 50%; background: #2f6b3a; color: #fff; font-size: .8rem; font-weight: 700;
    }
    .nx-toast--error .nx-toast__icon { background: #9e2a1b; }
    .nx-toast__message { margin: 0; flex: 1; padding-top: .05rem; }
    .nx-toast__close {
        flex: none; background: none; border: 0; color: inherit; opacity: .7; cursor: pointer;
        font-size: 1.25rem; line-height: 1; padding: 0; min-width: 1.5rem; min-height: 1.5rem;
    }
    .nx-toast__close:focus-visible { outline: 2px solid #f7f5f0; outline-offset: 2px; border-radius: 4px; }
    .nx-toast__close:hover, .nx-toast__close:focus-visible { opacity: 1; }
    @keyframes nx-toast-in { from { opacity: 0; transform: translateY(.5rem); } to { opacity: 1; transform: none; } }
    @media (max-width: 30rem) {
        .nx-toaster { left: 1rem; right: 1rem; width: auto; bottom: calc(1rem + env(safe-area-inset-bottom, 0px)); }
    }
    @media (prefers-reduced-motion: reduce) {
        .nx-toast { animation: none; transition: none; }
    }
</style>
