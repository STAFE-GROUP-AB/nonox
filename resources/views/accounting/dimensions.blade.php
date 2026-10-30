@php($kind = App\Domain\Accounting\Dimension::from($dimension))

<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading
            :title="$kind->pluralLabel()"
            :lead="$kind === App\Domain\Accounting\Dimension::CostCenter
                ? __('Dela upp bokföringen per restaurang, butik eller avdelning.')
                : __('Följ intäkter och kostnader för ett uppdrag eller en satsning.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.dimensions :dimension="$dimension" />
    </div>
</x-app-layout>
