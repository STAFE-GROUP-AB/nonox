{{--
    Real tabs (WAI-ARIA tabs pattern): a tablist with one tab in the tab
    order, arrow keys, Home and End to move between them, and the panel the
    selected tab controls. The tab is a Livewire property (model), so the
    choice survives a reload through the query string and every panel is
    paginated on the server. tabs: list of {key, label, count?}.
--}}
@props(['tabs', 'active', 'model' => 'flik', 'id' => 'crm-tabs', 'label'])

<div {{ $attributes }}>
    <div
        role="tablist"
        aria-label="{{ $label }}"
        class="-mx-1 flex gap-1 overflow-x-auto border-b border-stone-200 px-1"
        x-data
        x-on:keydown="
            const tabs = [...$el.querySelectorAll('[role=tab]')];
            const i = tabs.indexOf(document.activeElement);
            if (i < 0) return;
            let next = null;
            if ($event.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            if ($event.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            if ($event.key === 'Home') next = tabs[0];
            if ($event.key === 'End') next = tabs[tabs.length - 1];
            if (next) { $event.preventDefault(); next.focus(); next.click(); }
        "
    >
        @foreach($tabs as $tab)
            @php $selected = $tab['key'] === $active; @endphp
            <button
                type="button"
                role="tab"
                id="{{ $id }}-tab-{{ $tab['key'] }}"
                aria-controls="{{ $id }}-panel"
                aria-selected="{{ $selected ? 'true' : 'false' }}"
                tabindex="{{ $selected ? '0' : '-1' }}"
                wire:click="$set('{{ $model }}', '{{ $tab['key'] }}')"
                @class([
                    'relative -mb-px inline-flex min-h-11 flex-none items-center gap-2 border-b-2 px-3 text-sm font-medium focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent-600 motion-safe:transition-colors',
                    'border-ink text-ink' => $selected,
                    'border-transparent text-gray-600 hover:border-stone-300 hover:text-ink' => ! $selected,
                ])
            >
                {{ $tab['label'] }}
                @isset($tab['count'])
                    <span @class(['inline-flex min-w-6 justify-center rounded-full px-1.5 text-xs tabular-nums', 'bg-ink text-white' => $selected, 'bg-stone-100 text-gray-600' => ! $selected])>{{ $tab['count'] }}</span>
                @endisset
            </button>
        @endforeach
    </div>
    <div role="tabpanel" id="{{ $id }}-panel" aria-labelledby="{{ $id }}-tab-{{ $active }}" tabindex="0" class="pt-5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-600">
        {{ $slot }}
    </div>
</div>
