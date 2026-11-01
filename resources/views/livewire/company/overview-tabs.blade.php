<nav class="-mx-4 flex gap-6 overflow-x-auto border-b border-gray-200 px-4 text-sm font-medium sm:mx-0 sm:px-0" aria-label="{{ __('Visa') }}">
    @foreach(['oversikt' => __('Översikt'), 'stangning' => __('Månadsstängning'), 'manad' => __('Månad för månad'), 'uppgifter' => __('Per företag')] as $key => $label)
        <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
            '-mb-px shrink-0 whitespace-nowrap border-b-2 pb-3',
            'border-accent-600 text-accent-700' => $tab === $key,
            'border-transparent text-gray-600 hover:text-gray-900' => $tab !== $key,
        ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</button>
    @endforeach
</nav>
