<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading
            :title="$voucher ? __('Utkast') : __('Ny verifikation')"
            :lead="__('Varje rad bokförs på ett konto, antingen i debet eller i kredit. Summan av debet och kredit måste vara lika stor.')" />
        <livewire:accounting.voucher-editor :voucher="$voucher" :copy-of="$copyOf" />
    </div>
</x-app-layout>
