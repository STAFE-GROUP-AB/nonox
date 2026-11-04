<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Min startplan')" :lead="__('Det ett nytt företag behöver göra, i den ordning det brukar göras. Stegen väljs efter företagsform, bransch och om företaget är momsregistrerat eller arbetsgivare.')" />
        <livewire:guide.start-plan />
    </div>
</x-app-layout>
