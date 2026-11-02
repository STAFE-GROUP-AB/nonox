{{--
    One shift in the grid. In the week view it sits in its day's cell; on the
    timeline it is placed by its clock times. The data attributes are what
    the grid's script reads to drag, resize, copy and save it.
--}}
@php
    $timeline ??= false;
    $colour = $shift->area_id !== null ? ($areaById->get($shift->area_id)?->colour ?? '#a8a29e') : '#a8a29e';
    $startMin = $shift->starts_at->hour * 60 + $shift->starts_at->minute;
    $endOffset = (int) $shift->starts_at->startOfDay()->diffInDays($shift->ends_at->startOfDay());
    $endMin = $endOffset * 1440 + $shift->ends_at->hour * 60 + $shift->ends_at->minute;
    $shiftWarnings = $warnings[$shift->id] ?? [];
    $label = $chipLabel($shift);
    $missing = $shift->employee_id !== null && ($shift->cost_estimate_ore === null || ((array) ($shift->cost_details['missing'] ?? [])) !== []);
    $state = $shift->hasUnpublishedChanges() ? __('Ändrat, inte publicerat') : ($shift->isDraft() ? __('Utkast') : __('Publicerat'));
@endphp
<div
    wire:key="shift-{{ $shift->id }}"
    data-shift="{{ $shift->id }}"
    data-row="{{ $rows === 'avdelningar' ? ($shift->area_id ?? 'none') : ($shift->employee_id ?? 'open') }}"
    data-employee="{{ $shift->employee_id }}"
    data-date="{{ $shift->starts_at->toDateString() }}"
    data-start="{{ $shift->starts_at->format('H:i') }}"
    data-end="{{ $shift->ends_at->format('H:i') }}"
    data-end-offset="{{ $endOffset }}"
    data-start-min="{{ $startMin }}"
    data-end-min="{{ $endMin }}"
    data-published="{{ $shift->published_at ? '1' : '0' }}"
    data-cancel-question="{{ __('Ställa in passet? Det är publicerat, så personen får besked om att det är inställt.') }}"
    tabindex="0"
    role="button"
    aria-label="{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }} {{ $label }}. {{ $state }}.{{ $shiftWarnings !== [] ? ' '.trans_choice('{1} 1 varning.|[2,*] :count varningar.', count($shiftWarnings), ['count' => count($shiftWarnings)]) : '' }}"
    title="{{ collect($shiftWarnings)->map(fn ($warning) => $warning->message)->prepend($state)->join("\n") }}"
    @class([
        'group/chip relative overflow-hidden rounded-lg border-l-4 px-2 py-1 text-xs leading-tight outline-none ring-1 focus-visible:ring-2 focus-visible:ring-accent-500',
        'touch-manipulation select-none' => $canManage,
        'cursor-grab' => $canManage && ! $timeline,
        'absolute inset-y-1 cursor-move' => $timeline,
        'ring-stone-200' => ! $shift->isDraft(),
        'border-y border-r border-dashed border-stone-400 ring-transparent' => $shift->isDraft(),
    ])
    style="border-left-color: {{ $colour }}; background: {{ $shift->isDraft() ? '#fff' : $colour.'1f' }};{{ $timeline ? ' left: '.($startMin / 1440 * 100).'%; width: '.(max(15, min($endMin, 1440) - $startMin) / 1440 * 100).'%;' : '' }}"
>
    @if($timeline && $canManage)
        <span data-handle="start" class="absolute inset-y-0 left-0 w-2 cursor-ew-resize sm:w-1.5" aria-hidden="true"></span>
        <span data-handle="end" class="absolute inset-y-0 right-0 w-2 cursor-ew-resize sm:w-1.5" aria-hidden="true"></span>
    @endif
    <span class="flex items-center justify-between gap-1">
        <span data-time class="font-semibold tabular-nums text-ink">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
        <span class="flex shrink-0 items-center gap-1">
            @if($shiftWarnings !== [])
                <span class="rounded-full bg-amber-100 px-1.5 font-bold text-amber-900" aria-hidden="true">!{{ count($shiftWarnings) > 1 ? count($shiftWarnings) : '' }}</span>
            @endif
            @if($shift->isDraft())
                <span @class(['size-1.5 rounded-full', 'bg-accent-600' => $shift->hasUnpublishedChanges(), 'bg-stone-400' => ! $shift->hasUnpublishedChanges()]) aria-hidden="true"></span>
            @endif
        </span>
    </span>
    @if($label !== '')
        <span class="block truncate text-gray-700">{{ $label }}</span>
    @endif
    @if(! $timeline && $shift->employee_id !== null)
        <span class="block tabular-nums text-gray-500">{{ $shift->cost_estimate_ore === null ? '–' : $money($shift->cost_estimate_ore) }}{{ $missing ? ' *' : '' }}</span>
    @endif
</div>
