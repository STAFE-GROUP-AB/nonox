@props(['money', 'currency' => true, 'onDark' => false])

{{-- Amounts are right-aligned with tabular digits; negatives are marked in colour and with a minus sign (AGENTS.md §11.1). --}}
<span {{ $attributes->class([
    'tabular-nums whitespace-nowrap',
    'text-accent-700' => $money->isNegative() && ! $onDark,
    '!text-accent-400' => $money->isNegative() && $onDark,
]) }}>{{ $money->format($currency) }}</span>
