@props(['open' => false])

{{-- "Så fungerar segment" (ADR-048): what a segment is, when it is evaluated, three examples, and who is always left out. --}}
<details {{ $attributes->merge(['class' => 'group rounded-2xl bg-white p-4 ring-1 ring-stone-200 sm:p-6']) }} @if($open) open @endif data-testid="crm-segment-guide">
    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 font-semibold text-ink">
        {{ __('Så fungerar segment') }}
        <span class="text-sm font-medium text-accent-600 group-open:hidden">{{ __('Visa') }}</span>
        <span class="hidden text-sm font-medium text-accent-600 group-open:inline">{{ __('Dölj') }}</span>
    </summary>

    <div class="mt-3 grid gap-6 text-sm text-gray-700 lg:grid-cols-3">
        <div class="space-y-3">
            <h3 class="font-semibold text-ink">{{ __('Ett sparat filter, inte en lista') }}</h3>
            <p>{{ __('Ett segment beskriver vilka ett utskick ska gå till: du väljer egenskaper som status, taggar, ort eller ansvarig säljare, och NONOX tar med kontaktpersonerna på de kunder och prospekt som stämmer.') }}</p>
            <p>{{ __('Vilka som är med räknas fram när du skickar utskicket, inte när du sparar segmentet. En kund som får taggen i morgon kommer med i nästa utskick, och den som inte längre stämmer följer inte med.') }}</p>
            <p>{{ __('Alla filter du fyller i måste stämma. Inom ett filter räcker ett av valen: kryssar du både Prospekt och Aktiv kund kommer båda med.') }}</p>
        </div>

        <div class="space-y-3">
            <h3 class="font-semibold text-ink">{{ __('Tre exempel') }}</h3>
            <ul class="space-y-3">
                <li>
                    <span class="block font-medium text-ink">{{ __('Alla kunder i Stockholm med taggen Kaffe') }}</span>
                    {{ __('Status: Aktiv kund. Ort: Stockholm. Taggar: kaffe.') }}
                </li>
                <li>
                    <span class="block font-medium text-ink">{{ __('Prospekt där du är ansvarig') }}</span>
                    {{ __('Status: Prospekt. Ansvarig säljare: du själv.') }}
                </li>
                <li>
                    <span class="block font-medium text-ink">{{ __('Beslutsfattare hos byggföretag') }}</span>
                    {{ __('Roll: Beslutsfattare. SNI-kod börjar med: 41.') }}
                </li>
            </ul>
        </div>

        <div class="space-y-3">
            <h3 class="font-semibold text-ink">{{ __('Vilka som aldrig får utskicket') }}</h3>
            <p>{{ __('Några personer i ett segment lämnar NONOX alltid utanför, så att ni följer marknadsföringslagen och GDPR:') }}</p>
            <ul class="list-disc space-y-1 ps-5">
                <li>{{ __('de som saknar e-postadress;') }}</li>
                <li>{{ __('de som är markerade Kontakta inte eller har begränsad behandling;') }}</li>
                <li>{{ __('de som har avregistrerat sig, eller vars adress står på spärrlistan eller har studsat;') }}</li>
                <li>{{ __('privatpersoner, enskilda firmor och kunder utan organisationsnummer som inte har samtyckt till e-postreklam: för en fysisk person kräver lagen samtycke, om inte kundundantaget gäller.') }}</li>
            </ul>
            <p>{{ __('Rutan Personer i segmentet här, och rutan Mottagare i utskicket, visar hur många som lämnas utanför och av vilket skäl.') }}</p>
        </div>
    </div>
</details>
