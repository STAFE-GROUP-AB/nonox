@if($months->isNotEmpty() && $selected !== null)
    <div>
        <label for="saas-month" class="block text-sm font-medium text-gray-700">{{ __('Månad') }}</label>
        <select id="saas-month" wire:change="$set('month', $event.target.value)" class="mt-1 block rounded-lg border-gray-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500">
            @foreach($months->reverse() as $key => $option)
                <option value="{{ substr($key, 0, 7) }}" @selected($key === $selected->key())>{{ \App\Modules\Saas\Domain\Months::label($key) }}{{ $option->isClosed() ? '' : ' ('.__('öppen').')' }}</option>
            @endforeach
        </select>
    </div>
@endif
