{{--
    Initials on a calm tile: round for a person, rounded square for a
    company. No photos. The tint follows the name, so the same contact
    always gets the same colour among NONOX's warm neutrals and the accent.
--}}
@props(['name' => '', 'shape' => 'round', 'size' => 'lg'])

@php
    $words = preg_split('/[\s\-]+/u', trim((string) preg_replace('/\b(AB|HB|KB|Ek\.? ?för\.?)\b/u', '', $name))) ?: [];
    $words = array_values(array_filter($words, fn (string $word): bool => $word !== '' && preg_match('/^\p{L}/u', $word) === 1));
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
    $tones = [
        'bg-ink text-white',
        'bg-accent-600 text-white',
        'bg-stone-700 text-white',
        'bg-accent-100 text-accent-900',
        'bg-stone-200 text-ink',
    ];
    $tone = $tones[abs(crc32($name)) % count($tones)];
    $dimension = match ($size) {
        'sm' => 'size-9 text-sm',
        'md' => 'size-12 text-base',
        default => 'size-14 text-lg sm:size-20 sm:text-2xl',
    };
@endphp

<span {{ $attributes->class([
    'inline-flex flex-none select-none items-center justify-center font-bold tracking-tight shadow-xs ring-4 ring-white',
    $dimension,
    $tone,
    'rounded-full' => $shape === 'round',
    'rounded-2xl sm:rounded-3xl' => $shape !== 'round',
]) }} aria-hidden="true">{{ $initials }}</span>
