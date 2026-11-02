<x-ui.card :title="__('Underlag')">
    @if($attachments->isEmpty())
        <p class="text-sm text-gray-600">{{ __('Inget underlag ännu. En verifikation ska ha ett kvitto eller en faktura som visar vad som hänt.') }}</p>
    @else
        <ul class="space-y-1 text-sm">
            @foreach($attachments as $attachment)
                <li wire:key="attachment-{{ $attachment->id }}" class="flex items-center justify-between gap-2">
                    <button type="button" wire:click="select({{ $attachment->id }})" @class([
                        'truncate text-left',
                        'font-semibold text-accent-700' => $selected?->id === $attachment->id,
                        'text-gray-700 hover:text-accent-700' => $selected?->id !== $attachment->id,
                    ])>{{ $attachment->original_name }}</button>
                    <span class="flex-none text-xs text-gray-500">{{ $attachment->humanSize() }}</span>
                </li>
            @endforeach
        </ul>

        @if($selected)
            @php($url = route('accounting.vouchers.attachment', [$voucher, $selected->id]))
            <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                @if($selected->isImage())
                    <img src="{{ $url }}" alt="{{ $selected->original_name }}" class="w-full object-contain">
                @elseif($selected->mime === 'application/pdf')
                    <iframe src="{{ $url }}" title="{{ $selected->original_name }}" class="h-128 w-full"></iframe>
                @else
                    <p class="p-4 text-sm text-gray-700">{{ __('Filen kan inte visas här. Ladda ner den för att öppna den.') }} <a href="{{ $url }}" class="font-medium text-accent-700 underline">{{ __('Ladda ner') }}</a></p>
                @endif
            </div>
            <div class="mt-2 flex items-center justify-between text-sm">
                <a href="{{ $url }}" target="_blank" class="text-accent-600 hover:text-accent-700">{{ __('Öppna i nytt fönster') }}</a>
                @if($canRemove)
                    <button type="button" class="text-gray-600 underline" wire:click="remove({{ $selected->id }})">{{ __('Ta bort') }}</button>
                @endif
            </div>
            <p class="mt-2 break-all text-xs text-gray-500">{{ __('Kontrollsumma') }}: <span class="font-mono">{{ $selected->sha256 }}</span></p>
        @endif
    @endif

    @if($canAttach)
        <div
            x-data="{ over: false }"
            x-on:dragover.prevent="over = true"
            x-on:dragleave.prevent="over = false"
            x-on:drop.prevent="over = false; $wire.uploadMultiple('uploads', $event.dataTransfer.files)"
            :class="over ? 'border-accent-500 bg-accent-50' : 'border-gray-300'"
            class="mt-4 rounded-lg border-2 border-dashed px-4 py-5 text-center text-sm"
        >
            <p class="text-gray-700">{{ __('Dra hit en fil, eller') }}
                <label for="attachment_uploads" class="cursor-pointer font-semibold text-accent-600 hover:text-accent-700">{{ __('välj fil') }}</label>
            </p>
            <input id="attachment_uploads" type="file" class="sr-only" wire:model="uploads" multiple accept="application/pdf,image/jpeg,image/png,image/webp">
            <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="uploads">{{ __('Laddar upp…') }}</p>
        </div>
        @error('uploads') <p class="mt-2 text-sm text-accent-700" role="alert">{{ $message }}</p> @enderror
    @endif
</x-ui.card>
