{{--
    The CRM's icons, drawn for NONOX like the overview's: simple strokes on a
    24-unit grid in the current text colour. Decorative; the label says it.
--}}
@props(['name' => 'note'])

<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('CALL')
        @case('phone')
            <path d="M5 4h3.5l1.5 4-2 1.5a11 11 0 0 0 6.5 6.5l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.5 1.5A15.5 15.5 0 0 1 3.5 5.5 1.5 1.5 0 0 1 5 4z" />
            @break
        @case('EMAIL')
        @case('mail')
            <rect x="3" y="5.5" width="18" height="13" rx="2.5" /><path d="m3.5 7 8.5 6 8.5-6" />
            @break
        @case('MEETING')
        @case('calendar')
            <rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path d="M3.5 10h17M8 3v4M16 3v4" />
            @break
        @case('TASK')
            <rect x="4" y="4" width="16" height="16" rx="3" /><path d="m8.5 12 2.5 2.5 4.5-5" />
            @break
        @case('deal')
            <path d="M3 7.5h18v11H3z" /><path d="M8.5 7.5V5.5h7v2" /><path d="M3 12.5h18" />
            @break
        @case('quote')
            <path d="M7 3h7l4 4v6" /><path d="M14 3v4h4" /><path d="M7 3v18h6" /><path d="M10 11h5M10 15h3" /><path d="m15 19 1.5 1.5L20 17" />
            @break
        @case('order')
            <path d="m4 7.5 8-4 8 4v9l-8 4-8-4z" /><path d="m4 7.5 8 4 8-4M12 11.5v9" />
            @break
        @case('invoice')
        @case('credit')
            <path d="M7 3h7l4 4v14H7z" /><path d="M14 3v4h4" /><path d="M10 12h5M10 16h5" />
            @break
        @case('payment')
            <rect x="3" y="6" width="18" height="12" rx="2.5" /><path d="M3 10h18M7 15h3" />
            @break
        @case('reminder')
            <path d="M12 4a5 5 0 0 0-5 5v3.5L5.5 15h13L17 12.5V9a5 5 0 0 0-5-5z" /><path d="M10 18a2 2 0 0 0 4 0" />
            @break
        @case('check')
            <path d="m5 12.5 4.5 4.5L19 7.5" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('building')
            <path d="M4 20V5.5L12 3v17M12 8h8v12" /><path d="M7 8h2M7 12h2M7 16h2M15 12h2M15 16h2M3 20h18" />
            @break
        @case('person')
            <circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
            @break
        @case('shield')
            <path d="M12 3 5 6v5.5c0 4.3 3 7.7 7 9.5 4-1.8 7-5.2 7-9.5V6z" /><path d="m9 12 2 2 4-4" />
            @break
        @case('globe')
            <circle cx="12" cy="12" r="8.5" /><path d="M3.5 12h17M12 3.5c2.5 2.6 3.5 5.4 3.5 8.5s-1 5.9-3.5 8.5c-2.5-2.6-3.5-5.4-3.5-8.5s1-5.9 3.5-8.5z" />
            @break
        @case('pin')
            <path d="M12 21s-6.5-5.5-6.5-11a6.5 6.5 0 0 1 13 0c0 5.5-6.5 11-6.5 11z" /><circle cx="12" cy="10" r="2.25" />
            @break
        @case('block')
            <circle cx="12" cy="12" r="8.5" /><path d="m6 6 12 12" />
            @break
        @default
            <path d="M6 4h9l3 3v13H6z" /><path d="M9 10h6M9 14h6M9 18h3" />
    @endswitch
</svg>
