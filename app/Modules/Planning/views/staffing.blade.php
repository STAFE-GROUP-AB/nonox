<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Bemanning och kostnad')" :lead="__('Planerad och faktisk tid med uppskattad personalkostnad mot försäljningen i bokföringen, per dag och vecka. Kostnaderna är uppskattningar: lönen räknas i lönekörningen.')" />
        <livewire:planning.staffing />
    </div>
</x-app-layout>
