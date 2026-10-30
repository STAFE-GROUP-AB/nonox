@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold',
    'bg-stone-100 text-gray-700' => $tone === 'neutral',
    'bg-accent-100 text-accent-800' => $tone === 'accent',
    'bg-green-50 text-green-800' => $tone === 'success',
]) }}>{{ $slot }}</span>
