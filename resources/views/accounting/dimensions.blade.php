@php($kind = App\Domain\Accounting\Dimension::from($dimension))

<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading
            :title="$kind->pluralLabel()"
            :lead="$kind === App\Domain\Accounting\Dimension::CostCenter
                ? __('Dela upp bokföringen per restaurang, butik eller avdelning.')
                : __('Följ intäkter och kostnader för ett uppdrag eller en satsning.')" />
        <livewire:accounting.dimensions :dimension="$dimension" />
    </div>
</x-app-layout>
