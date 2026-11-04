@props(['url'])
{{-- The NONOX logo as PNG (mail clients do not show SVG), by absolute URL so it loads from the instance. The alt text carries the name when images are blocked. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/nonox-mail.png') }}" class="logo" width="147" height="32" alt="{{ trim(strip_tags((string) $slot)) }}">
</a>
</td>
</tr>
