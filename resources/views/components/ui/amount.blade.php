@props(['money', 'currency' => true])

{{-- Amounts are right-aligned with tabular digits; negatives are marked in colour and with a minus sign (AGENTS.md §11.1). --}}
<span {{ $attributes->class([
    'tabular-nums whitespace-nowrap',
    'text-accent-700' => $money->isNegative(),
]) }}>{{ $money->format($currency) }}</span>
