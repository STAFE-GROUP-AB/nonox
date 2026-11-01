{{-- One step of one client's month: $row, $step, $cell, $states. A derived "Klar" cannot be undone by hand. --}}
@php
    [$stateLabel, $tone] = $states[$cell['state']];
    $clickable = $row['can_check'] && ($cell['manual'] || $cell['state'] !== 'done') && $cell['state'] !== 'na';
    $detail = match (true) {
        $cell['manual'] => __('Avbockat av :name :date', ['name' => $cell['checked_by'], 'date' => $cell['checked_at']]),
        $cell['due'] !== null => __('Sista dag :date', ['date' => $cell['due']]),
        $cell['count'] !== null => __(':count kvar', ['count' => $cell['count']]),
        default => $step->hint(),
    };
@endphp
@if($clickable)
    <button type="button" wire:click="toggle({{ $row['company_id'] }}, '{{ $step->value }}')" title="{{ $detail }}"
            aria-label="{{ __(':step för :company: :state. :detail', ['step' => $step->label(), 'company' => $row['company_name'], 'state' => $stateLabel, 'detail' => $detail]) }}"
            class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 transition hover:brightness-95 {{ $tone }}">
        <span class="md:hidden">{{ $step->label() }}:</span>
        @if($cell['state'] === 'done')<span aria-hidden="true">✓</span>@endif
        {{ $stateLabel }}@if($cell['count'] !== null) <span class="tabular-nums">({{ $cell['count'] }})</span>@endif
    </button>
@else
    <span title="{{ $detail }}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $tone }}">
        <span class="md:hidden">{{ $step->label() }}:</span>
        @if($cell['state'] === 'done')<span aria-hidden="true">✓</span>@endif
        {{ $stateLabel }}@if($cell['count'] !== null) <span class="tabular-nums">({{ $cell['count'] }})</span>@endif
    </span>
@endif
