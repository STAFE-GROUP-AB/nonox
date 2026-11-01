{{--
    The icons of "Att göra" on Översikt, drawn for NONOX: simple strokes on a
    24-unit grid, in the current text colour. Decorative; the title says it.
--}}
@props(['name' => 'task'])

<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('vat')
            <path d="M6 18 18 6" /><circle cx="7.5" cy="7.5" r="2.5" /><circle cx="16.5" cy="16.5" r="2.5" />
            @break
        @case('payroll')
            <rect x="3" y="6" width="18" height="13" rx="2.5" /><path d="M3 10h18" /><circle cx="16" cy="14.5" r="1.25" />
            @break
        @case('invoice')
            <path d="M7 3h7l4 4v14H7z" /><path d="M14 3v4h4" /><path d="M10 12h5M10 16h5" />
            @break
        @case('approve')
            <circle cx="12" cy="12" r="9" /><path d="m8 12.5 2.5 2.5L16 9.5" />
            @break
        @case('pay')
            <rect x="3" y="6" width="18" height="12" rx="2.5" /><path d="M3 10h18M7 15h3" />
            @break
        @case('bank')
            <path d="M3 9.5 12 4l9 5.5" /><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8" /><path d="M3 20h18" />
            @break
        @case('inbox')
            <path d="M4 13 6.5 5h11L20 13v6H4z" /><path d="M4 13h4.5l1 2h5l1-2H20" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
            @break
        @case('draft')
            <path d="m15 4 5 5L9 20H4v-5z" /><path d="m13 6 5 5" />
            @break
        @case('calendar')
            <rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path d="M3.5 10h17M8 3v4M16 3v4" />
            @break
        @default
            <path d="M9 6h11M9 12h11M9 18h11" /><path d="m3.5 6 1.5 1.5L7 5M3.5 12l1.5 1.5L7 11M3.5 18l1.5 1.5L7 17" />
    @endswitch
</svg>
