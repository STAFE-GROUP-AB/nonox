{{-- One label's boxes (LabelLayoutEngine), absolutely placed in mm inside a positioned parent. Shared by the PDF and the studio preview. --}}
@foreach($boxes as $box)
    @if($box->element === App\Modules\Inventory\Labels\LabelElement::Barcode)
        <img src="{{ $box->src }}" alt="" style="position:absolute;left:{{ $box->x }}mm;top:{{ $box->y }}mm;width:{{ $box->width }}mm;height:{{ $box->barsHeight }}mm">
        <div style="position:absolute;left:{{ $box->x }}mm;top:{{ $box->y + $box->barsHeight }}mm;width:{{ $box->width }}mm;font-size:{{ $box->captionPt }}pt;line-height:1.2;text-align:center;white-space:nowrap;overflow:hidden">{{ $box->caption }}</div>
    @elseif($box->element === App\Modules\Inventory\Labels\LabelElement::Logo)
        <div style="position:absolute;left:{{ $box->x }}mm;top:{{ $box->y }}mm;width:{{ $box->width }}mm;height:{{ $box->height }}mm;text-align:{{ $box->align }};overflow:hidden"><img src="{{ $box->src }}" alt="" style="height:{{ $box->height }}mm"></div>
    @elseif(! $box->isText())
        <img src="{{ $box->src }}" alt="" style="position:absolute;left:{{ $box->x }}mm;top:{{ $box->y }}mm;width:{{ $box->width }}mm;height:{{ $box->height }}mm">
    @else
        <div style="position:absolute;left:{{ $box->x }}mm;top:{{ $box->y }}mm;width:{{ $box->width }}mm;font-size:{{ $box->pt }}pt;line-height:1.2;font-weight:{{ $box->bold ? 'bold' : 'normal' }};text-align:{{ $box->align }};white-space:nowrap;overflow:hidden">@foreach($box->lines as $line){{ $line }}@if(! $loop->last)<br>@endif @endforeach</div>
    @endif
@endforeach
