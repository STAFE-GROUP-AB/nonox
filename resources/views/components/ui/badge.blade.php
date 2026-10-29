@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium',
    'bg-gray-100 text-gray-600' => $tone === 'neutral',
    'bg-accent-50 text-accent-700' => $tone === 'accent',
    'bg-green-50 text-green-800' => $tone === 'success',
]) }}>{{ $slot }}</span>
