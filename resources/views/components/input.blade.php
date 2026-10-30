@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'rounded-xl border-stone-300 shadow-sm focus:border-accent-500 focus:ring-accent-500']) !!}>
