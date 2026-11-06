@props(['model' => null, 'name' => 'figures_reviewed'])

{{-- "Jag har granskat siffrorna": required before every filing and payment file (owner decision 10). --}}
<div {{ $attributes->class(['text-sm text-gray-700']) }}>
    <label class="flex items-start gap-2">
        <input type="checkbox" name="{{ $name }}" value="1" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" @if($model) wire:model.live="{{ $model }}" @endif {{ $attributes->only('x-model') }}>
        <span>
            <span class="font-medium">{{ __('Jag har granskat siffrorna') }}</span>
            <span class="block text-gray-600">{{ __('Du som kund ansvarar för att uppgifterna är riktiga.') }}</span>
        </span>
    </label>
    @error($name) <p class="mt-1 text-accent-700" role="alert">{{ $message }}</p> @enderror
</div>
