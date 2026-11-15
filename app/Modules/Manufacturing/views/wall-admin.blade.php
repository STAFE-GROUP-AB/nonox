<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Storskärm')" :lead="__('En tv på väggen i verkstaden som visar dagens tillverkning, vad som är försenat och vad som ska levereras. Ingen behöver vara inloggad vid skärmen.')" />
        <livewire:manufacturing.wall-screen-admin />
    </div>
</x-app-layout>
