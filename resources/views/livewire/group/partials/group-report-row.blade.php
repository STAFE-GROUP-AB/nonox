{{-- One line of a group report: the label, Företagen and Elimineringar when they are shown, then Koncernen. --}}
<tr class="{{ $class }}">
    <td class="py-1.5 pr-4">{{ $label }}</td>
    @if($columns['companies'] !== null)
        <td class="py-1.5 pl-4 text-right"><x-ui.amount :money="\App\Support\Money\Money::ofOre($columns['companies'])" /></td>
        <td class="py-1.5 pl-4 text-right"><x-ui.amount :money="\App\Support\Money\Money::ofOre($columns['eliminations'])" /></td>
    @endif
    <td class="py-1.5 pl-4 text-right"><x-ui.amount :money="\App\Support\Money\Money::ofOre($columns['group'])" /></td>
</tr>
