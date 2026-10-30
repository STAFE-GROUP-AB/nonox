{{-- The company's colours on its documents (App\Support\Branding\Brand). --}}
    .brand-bar { position: fixed; top: -18mm; left: -16mm; right: -16mm; height: 3.5mm; background: {{ $brand->color }}; }
    h1 { color: {{ $brand->accent }}; }
    .total td { border-top-color: {{ $brand->color }}; }
    .total td.right { color: {{ $brand->color }}; }
    .box { border-color: {{ $brand->tint(0.6) }}; background: {{ $brand->tint(0.95) }}; }
    .rows th { border-bottom-color: {{ $brand->color }}; }
    .logo { max-height: 18mm; max-width: 62mm; margin-bottom: 2mm; }
