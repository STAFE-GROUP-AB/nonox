@props(['id' => null, 'maxWidth' => null])

@php($id ??= md5($attributes->wire('model')))

<x-modal :id="$id" :maxWidth="$maxWidth" :labelledby="$id.'-title'" {{ $attributes }}>
    <div class="px-6 py-4">
        <h2 id="{{ $id }}-title" class="text-lg font-medium text-gray-900">
            {{ $title }}
        </h2>

        <div class="mt-4 text-sm text-gray-600">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row justify-end px-6 py-4 bg-gray-100 text-end">
        {{ $footer }}
    </div>
</x-modal>
