<nav class="flex gap-6 border-b border-gray-200 text-sm font-medium" aria-label="{{ __('Visa') }}">
    @foreach(['manad' => __('Månad för månad'), 'uppgifter' => __('Vad som väntar')] as $key => $label)
        <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
            '-mb-px border-b-2 pb-3',
            'border-accent-600 text-accent-700' => $tab === $key,
            'border-transparent text-gray-600 hover:text-gray-900' => $tab !== $key,
        ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</button>
    @endforeach
</nav>
