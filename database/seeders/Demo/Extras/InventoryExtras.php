<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Modules\Inventory\Actions\ArticleImages;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Domain\Gtin;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Http\UploadedFile;

/**
 * Lager in the shop, for the manual's inventory pages and the landing page:
 * a back store with zones, shelves and bins and the shop floor, twenty
 * stocked articles with made-up EAN codes, goods receipts through the year
 * at rising costs (one partly received, one still to come against the
 * latest supplier invoice), goods moved out to the shop floor, picked
 * orders for the hotel customer (one being picked now), a completed
 * stocktake in the summer and one under way today. The label templates come
 * with the module. The till integrations stay off: they need a till
 * account.
 *
 * The EAN codes are GS1 prefix 20, which GS1 keeps for a company's internal
 * use, so no code belongs to a real product. The pictures are drawn here.
 * Nothing is booked: Lager writes to the ledger only when the stock change
 * is booked at the year end.
 */
final class InventoryExtras extends DemoExtra
{
    /**
     * Back store HL with zones A (inredning) and B (textil), and the shop
     * floor BU. Each entry: full code => [type, name].
     */
    private const array LOCATIONS = [
        'HL' => [LocationType::Warehouse, 'Förrådet'],
        'HL-A' => [LocationType::Zone, 'Inredning'],
        'HL-A-01' => [LocationType::Shelf, 'Ljus och lyktor'],
        'HL-A-01-1' => [LocationType::Bin, null],
        'HL-A-01-2' => [LocationType::Bin, null],
        'HL-A-01-3' => [LocationType::Bin, null],
        'HL-A-02' => [LocationType::Shelf, 'Vaser och krukor'],
        'HL-A-03' => [LocationType::Shelf, 'Brickor, ramar och korgar'],
        'HL-B' => [LocationType::Zone, 'Textil'],
        'HL-B-01' => [LocationType::Shelf, 'Kuddar och plädar'],
        'HL-B-02' => [LocationType::Shelf, 'Kök och bad'],
        'HL-B-02-1' => [LocationType::Bin, null],
        'HL-B-02-2' => [LocationType::Bin, null],
        'BU' => [LocationType::Warehouse, 'Butiken'],
        'BU-01' => [LocationType::Shelf, 'Fönsterbordet'],
        'BU-02' => [LocationType::Shelf, 'Väggen vid kassan'],
    ];

    /**
     * number, name, price ex VAT (a round price with VAT), purchase price, default location, reorder
     * point, reorder quantity, picture (shape and colour), supplier (g =
     * Inredningsgrossisten, t = Textilhuset), first delivery.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: string, 8: string, 9: string, 10: int}>
     */
    private const array ARTICLES = [
        ['L-101', 'Doftljus fikon 220 g', '199,20', '72', 'HL-A-01-1', 10, 24, 'candle', 'b0516b', 'g', 36],
        ['L-102', 'Blockljus vit 7 × 10 cm', '63,20', '19', 'HL-A-01-2', 30, 60, 'candle', 'f3eee4', 'g', 96],
        ['L-103', 'Ljuslykta rökfärgat glas', '159,20', '58', 'HL-A-01-3', 8, 20, 'lantern', '6b6560', 'g', 24],
        ['L-201', 'Vas keramik sand 25 cm', '319,20', '118', 'HL-A-02', 5, 12, 'vase', 'd8c3a5', 'g', 14],
        ['L-202', 'Vas klarglas 30 cm', '239,20', '86', 'HL-A-02', 5, 12, 'vase', 'b9d4d6', 'g', 12],
        ['L-203', 'Kruka terrakotta 14 cm', '103,20', '35', 'HL-A-02', 12, 30, 'pot', 'c0703f', 'g', 40],
        ['L-301', 'Serveringsbricka ek 30 cm', '279,20', '104', 'HL-A-03', 4, 10, 'tray', 'b48a5a', 'g', 10],
        ['L-302', 'Fotoram ek 21 × 30', '159,20', '55', 'HL-A-03', 6, 16, 'frame', 'a87b4f', 'g', 20],
        ['L-303', 'Spegel rund 50 cm', '639,20', '245', 'HL-A-03', 6, 6, 'mirror', 'c9b28f', 'g', 6],
        ['L-304', 'Korg sjögräs stor', '359,20', '128', 'HL-A-03', 4, 10, 'basket', 'a38b5f', 'g', 12],
        ['T-101', 'Kuddfodral linne sand 50 × 50', '199,20', '64', 'HL-B-01', 10, 24, 'cushion', 'd9c7aa', 't', 30],
        ['T-102', 'Kuddfodral sammet grön 50 × 50', '239,20', '79', 'HL-B-01', 8, 20, 'cushion', '3f6b52', 't', 24],
        ['T-103', 'Innerkudde 50 × 50', '119,20', '38', 'HL-B-01', 12, 30, 'cushion', 'f4f2ee', 't', 36],
        ['T-201', 'Pläd ull grå 130 × 170', '719,20', '265', 'HL-B-01', 6, 8, 'throw', '8a8d91', 't', 10],
        ['T-202', 'Pläd bomull beige 130 × 170', '479,20', '168', 'HL-B-01', 3, 8, 'throw', 'd6c4a3', 't', 10],
        ['T-301', 'Bordslöpare linne 40 × 150', '239,20', '82', 'HL-B-02-1', 4, 12, 'throw', 'c7b9a3', 't', 14],
        ['T-302', 'Kökshandduk randig, 2-pack', '127,20', '41', 'HL-B-02-1', 10, 24, 'towel', '4b6c8f', 't', 40],
        ['T-303', 'Linneduk 145 × 250', '559,20', '205', 'HL-B-02-2', 6, 6, 'throw', 'ebe3d3', 't', 6],
        ['T-304', 'Badlakan bomull 70 × 140', '239,20', '84', 'HL-B-02-2', 6, 16, 'towel', 'e7ddd0', 't', 20],
        ['T-305', 'Handduk bomull 50 × 70', '119,20', '39', 'HL-B-02-2', 10, 24, 'towel', '9fb4a6', 't', 30],
    ];

    /** @var array<string, int> location ids by full code */
    private array $locations = [];

    /** @var array<string, Article> by article number */
    private array $articles = [];

    public function industries(): array
    {
        return ['bokforing-for-butiker'];
    }

    public function afterAll(DemoContext $context): void
    {
        app(ModuleManager::class)->enable(InventoryModule::KEY, $context->company, $context->user);

        $this->locations($context);
        $this->articles($context);

        // In date order: a count takes the stock as it is when it starts.
        $suppliers = $this->suppliers();
        $this->receipt($context, $suppliers['g'], $this->day($context, 2, 10), 'FS-20418', $this->firstDelivery('g'));
        $this->receipt($context, $suppliers['t'], $this->day($context, 3, 4), 'TH-8812', $this->firstDelivery('t'));
        $this->shopFloor($context, 3);
        $this->pickOrder($context, 4, [['T-101', 18], ['T-103', 18], ['T-201', 4], ['L-302', 6]]);
        $this->receipt($context, $suppliers['g'], $this->day($context, 6, 9), 'FS-21177', [['L-101', 24, '76'], ['L-102', 60, '20'], ['L-203', 30, '37'], ['L-201', 12, '124']]);
        $this->shopFloor($context, 6);
        $this->summerCount($context);
        $this->pickOrder($context, 7, [['T-304', 10], ['T-305', 20], ['T-302', 12], ['L-102', 40]]);
        $this->partialReceipt($context);
        $this->pickOrder($context, 10, [['L-101', 12], ['L-201', 6], ['L-203', 14], ['T-301', 8]]);
        $this->shopFloor($context, 10);

        $this->expectedReceipt($context);
        $this->openPickList($context);
        $this->todaysCount($context);
    }

    /**
     * Every article from one supplier, at its first quantity and purchase price.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function firstDelivery(string $supplier): array
    {
        $rows = [];

        foreach (self::ARTICLES as $row) {
            if ($row[9] === $supplier) {
                $rows[] = [$row[0], $row[10], $row[3]];
            }
        }

        return $rows;
    }

    private function day(DemoContext $c, int $month, int $day): CarbonImmutable
    {
        return $c->start->setDate($c->start->year, $month, $day);
    }

    private function locations(DemoContext $c): void
    {
        $save = app(SaveLocation::class);

        foreach (self::LOCATIONS as $fullCode => [$type, $name]) {
            $parts = explode('-', $fullCode);
            $code = array_pop($parts);
            $parent = $parts === [] ? null : $this->locations[implode('-', $parts)];
            $location = $save($c->user, $c->company, [
                'type' => $type->value,
                'parent_id' => $parent,
                'code' => $code,
                'name' => $name,
                ...($fullCode === 'HL' ? ['address_line1' => 'Exempelgatan 3', 'postal_code' => $c->demo['postal_code'], 'city' => $c->demo['city']] : []),
            ]);
            $this->locations[$fullCode] = $location->id;
        }
    }

    private function articles(DemoContext $c): void
    {
        $save = app(SaveArticle::class);
        $images = app(ArticleImages::class);
        $suppliers = $this->suppliers();

        foreach (self::ARTICLES as $index => [$number, $name, $price, $cost, $location, $reorderPoint, $reorderQuantity, $shape, $colour, $supplier]) {
            $article = $save($c->user, $c->company, [
                'number' => $number,
                'name' => $name,
                'unit' => 'st',
                'price' => $price,
                'vat_rate' => 2500,
                'gtin' => self::gtin($index + 1),
                'sku' => 'LG-'.str_replace('-', '', $number),
                'brand' => 'Lindgården',
                'purchase_price' => $cost,
                'default_supplier_id' => $suppliers[$supplier]->id,
                'supplier_article_number' => mb_strtoupper($supplier).'-'.(4100 + $index * 7),
                'is_stock_item' => true,
                'reorder_point' => (string) $reorderPoint,
                'reorder_quantity' => (string) $reorderQuantity,
                'default_location_id' => $this->locations[$location],
            ]);
            $this->articles[$number] = $article;

            $file = $this->picture($shape, $colour);

            try {
                $images->add($c->user, $c->company, $article, new UploadedFile($file, "{$number}.png", 'image/png', null, true), $name);
            } finally {
                @unlink($file);
            }
        }
    }

    /** Part of the September textiles came; the rest is on back order. */
    private function partialReceipt(DemoContext $c): void
    {
        $partial = $this->receipt($c, $this->suppliers()['t'], $this->day($c, 9, 15), 'TH-9340', [['T-101', 24, '67'], ['T-102', 20, '82'], ['T-201', 8, '279'], ['T-302', 24, '43']], receiveAll: false);
        $lines = $partial->lines()->get();
        $line = fn (string $number): int => $lines->firstOrFail('article_id', $this->articles[$number]->id)->id;

        app(ReceiveStock::class)($c->user, $c->company, $partial, [
            $line('T-101') => ['quantity' => '24'],
            $line('T-102') => ['quantity' => '12'],
            $line('T-302') => ['quantity' => '24'],
        ], $this->day($c, 9, 17));
    }

    /** The Christmas range, expected this week against the latest invoice from the wholesaler. */
    private function expectedReceipt(DemoContext $c): void
    {
        $supplier = $this->suppliers()['g'];
        $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->orderByDesc('invoice_date')->first();

        app(SaveReceipt::class)($c->user, $c->company, [
            'supplier_id' => $supplier->id,
            'supplier_invoice_id' => $invoice?->id,
            'expected_on' => $c->today->addDays(3)->toDateString(),
            'reference' => 'FS-22036',
            'note' => 'Julsortiment, ljus och lyktor',
            'lines' => [
                ['article_id' => $this->articles['L-101']->id, 'quantity' => '48', 'unit_cost' => '78'],
                ['article_id' => $this->articles['L-102']->id, 'quantity' => '120', 'unit_cost' => '21'],
                ['article_id' => $this->articles['L-103']->id, 'quantity' => '24', 'unit_cost' => '60'],
                ['article_id' => $this->articles['L-301']->id, 'quantity' => '10', 'unit_cost' => '108'],
            ],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $rows  article number, quantity, unit cost
     */
    private function receipt(DemoContext $c, Supplier $supplier, CarbonImmutable $date, string $reference, array $rows, bool $receiveAll = true): StockReceipt
    {
        $receipt = app(SaveReceipt::class)($c->user, $c->company, [
            'supplier_id' => $supplier->id,
            'expected_on' => $date->toDateString(),
            'reference' => $reference,
            'lines' => array_map(fn (array $row): array => ['article_id' => $this->articles[$row[0]]->id, 'quantity' => (string) $row[1], 'unit_cost' => $row[2]], $rows),
        ]);

        return $receiveAll ? app(ReceiveStock::class)->all($c->user, $c->company, $receipt, $date) : $receipt;
    }

    /** What stands out in the shop is moved from the back store, a few times a year. */
    private function shopFloor(DemoContext $c, int $inMonth): void
    {
        $move = app(RecordMovement::class);
        $moves = [
            [3, 'L-101', 'BU-01', 8], [3, 'L-103', 'BU-01', 6], [3, 'T-102', 'BU-02', 6], [3, 'T-201', 'BU-02', 3],
            [6, 'L-201', 'BU-01', 4], [6, 'L-202', 'BU-01', 3], [10, 'L-101', 'BU-01', 10], [10, 'T-101', 'BU-02', 8],
        ];

        foreach ($moves as [$month, $number, $to, $quantity]) {
            if ($month !== $inMonth) {
                continue;
            }

            $article = $this->articles[$number];
            $move($c->user, $c->company, [
                'kind' => 'transfer',
                'article_id' => $article->id,
                'location_id' => $article->default_location_id,
                'to_location_id' => $this->locations[$to],
                'quantity' => (string) $quantity,
                'date' => $this->day($c, $month, 20)->toDateString(),
                'note' => 'Påfyllning i butiken',
            ]);
        }
    }

    /**
     * One of the hotel's orders, picked and sent in the middle of the month.
     *
     * @param  list<array{0: string, 1: int}>  $rows
     */
    private function pickOrder(DemoContext $c, int $month, array $rows): void
    {
        $pick = app(PickLists::class);
        $list = $pick->manual($c->user, $c->company, $this->rows($rows), 'Hotell Exempel AB, beställning '.$this->day($c, $month, 1)->format('Y-m'));
        $pick->complete($c->user, $c->company, $list, $this->day($c, $month, 14));
    }

    /** Today's order from the hotel: three lines picked, one of them short. */
    private function openPickList(DemoContext $c): void
    {
        $pick = app(PickLists::class);
        $open = $pick->manual($c->user, $c->company, $this->rows([['L-303', 2], ['L-304', 4], ['T-202', 3], ['T-102', 6], ['L-102', 24]]), 'Hotell Exempel AB, nya rum på plan 3');
        $lines = $open->lines()->get();
        $line = fn (string $number): PickListLine => $lines->firstOrFail('article_id', $this->articles[$number]->id);

        $pick->pick($c->user, $c->company, $line('L-303'), '2');
        $pick->pick($c->user, $c->company, $line('L-304'), '3', 'DAMAGED');
        $pick->pick($c->user, $c->company, $line('T-202'), '3');
    }

    /**
     * @param  list<array{0: string, 1: int}>  $rows
     * @return list<array{article_id: int, quantity: string}>
     */
    private function rows(array $rows): array
    {
        return array_map(fn (array $row): array => ['article_id' => $this->articles[$row[0]]->id, 'quantity' => (string) $row[1]], $rows);
    }

    /** The half-year count of the textile zone: one throw damaged, two towels miscounted earlier. */
    private function summerCount(DemoContext $c): void
    {
        $counts = app(StockCounts::class);
        $summer = $counts->start($c->user, $c->company, $this->locations['HL-B'], $this->day($c, 6, 30), 'Halvårsinventering textil');

        foreach ($summer->lines()->with(['article:id,number', 'stockCount'])->get() as $line) {
            $short = match ($line->article?->number) {
                'T-202' => 1,
                'T-302' => 2,
                default => 0,
            };
            $counts->record($c->user, $c->company, $line, (string) intdiv($line->system_milli - $short * 1000, 1000), match ($short) {
                0 => null,
                1 => 'DAMAGED',
                default => 'COUNT_ERROR',
            });
        }

        $counts->complete($c->user, $c->company, $summer);
    }

    /** Today's count of the candle shelf, half done: one line as expected, one with three missing. */
    private function todaysCount(DemoContext $c): void
    {
        $counts = app(StockCounts::class);
        $count = $counts->start($c->user, $c->company, $this->locations['HL-A-01'], $c->today, 'Inför julsortimentet');
        $lines = $count->lines()->with(['article:id,number', 'stockCount'])->get()->keyBy(fn (StockCountLine $line): string => (string) $line->article?->number);

        if (isset($lines['L-101'])) {
            $counts->record($c->user, $c->company, $lines['L-101'], (string) intdiv($lines['L-101']->system_milli, 1000));
        }

        if (isset($lines['L-102'])) {
            $counts->record($c->user, $c->company, $lines['L-102'], (string) (intdiv($lines['L-102']->system_milli, 1000) - 3), 'THEFT');
        }
    }

    /** @return array{g: Supplier, t: Supplier} */
    private function suppliers(): array
    {
        return [
            'g' => Supplier::query()->where('name', 'Inredningsgrossisten Demo AB')->firstOrFail(),
            't' => Supplier::query()->where('name', 'Textilhuset Demo AB')->firstOrFail(),
        ];
    }

    /** A GTIN-13 under GS1 prefix 20 (internal use): 20400310, a serial and the check digit. */
    public static function gtin(int $serial): string
    {
        $body = '20400310'.sprintf('%04d', $serial);

        return $body.Gtin::checkDigit($body);
    }

    /**
     * A plain drawing of the product on a light background, as a PNG in
     * a temporary file: no photograph of any real product.
     */
    private function picture(string $shape, string $hex): string
    {
        $size = 600;
        $image = imagecreatetruecolor($size, $size);
        $colour = fn (int $r, int $g, int $b): int => (int) imagecolorallocate($image, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
        [$r, $g, $b] = array_map(fn (string $pair): int => (int) hexdec($pair), str_split($hex, 2));
        $main = $colour($r, $g, $b);
        $dark = $colour((int) ($r * 0.75), (int) ($g * 0.75), (int) ($b * 0.75));
        $light = $colour((int) ($r + (255 - $r) * 0.35), (int) ($g + (255 - $g) * 0.35), (int) ($b + (255 - $b) * 0.35));
        $background = $colour(244, 241, 236);
        $shadow = $colour(226, 221, 213);

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagefilledellipse($image, 300, 500, 380, 50, $shadow);

        match ($shape) {
            'candle' => (function () use ($image, $main, $dark, $colour): void {
                imagefilledrectangle($image, 210, 220, 390, 490, $main);
                imagefilledellipse($image, 300, 490, 180, 40, $main);
                imagefilledellipse($image, 300, 220, 180, 40, $dark);
                imageline($image, 300, 220, 300, 185, $colour(60, 50, 40));
                imagefilledellipse($image, 300, 160, 26, 52, $colour(242, 170, 60));
                imagefilledellipse($image, 300, 168, 12, 26, $colour(255, 230, 150));
            })(),
            'lantern' => (function () use ($image, $main, $light, $colour): void {
                imagefilledrectangle($image, 200, 170, 400, 490, $light);
                imagesetthickness($image, 8);
                imagerectangle($image, 200, 170, 400, 490, $main);
                imagefilledrectangle($image, 185, 150, 415, 175, $main);
                imagearc($image, 300, 150, 120, 110, 180, 360, $main);
                imagefilledrectangle($image, 260, 380, 340, 480, $colour(250, 248, 240));
                imagefilledellipse($image, 300, 355, 20, 40, $colour(242, 170, 60));
            })(),
            'vase' => imagefilledpolygon($image, [250, 130, 350, 130, 345, 190, 410, 300, 395, 470, 205, 470, 190, 300, 255, 190], $main),
            'pot' => (function () use ($image, $main, $dark): void {
                imagefilledpolygon($image, [190, 260, 410, 260, 380, 490, 220, 490], $main);
                imagefilledrectangle($image, 175, 225, 425, 270, $dark);
            })(),
            'tray' => (function () use ($image, $main, $dark): void {
                imagefilledellipse($image, 300, 360, 440, 200, $dark);
                imagefilledellipse($image, 300, 350, 410, 175, $main);
            })(),
            'frame' => (function () use ($image, $main, $colour): void {
                imagefilledrectangle($image, 175, 110, 425, 470, $main);
                imagefilledrectangle($image, 205, 140, 395, 440, $colour(250, 250, 248));
                imagefilledrectangle($image, 235, 170, 365, 410, $colour(205, 214, 220));
            })(),
            'mirror' => (function () use ($image, $main, $colour): void {
                imagefilledellipse($image, 300, 300, 400, 400, $main);
                imagefilledellipse($image, 300, 300, 360, 360, $colour(214, 226, 232));
                imagefilledellipse($image, 250, 240, 90, 160, $colour(236, 243, 246));
            })(),
            'basket' => (function () use ($image, $main, $dark): void {
                imagefilledpolygon($image, [160, 240, 440, 240, 410, 490, 190, 490], $main);

                for ($y = 270; $y < 490; $y += 32) {
                    imagesetthickness($image, 5);
                    imageline($image, 160 + (int) (($y - 240) * 0.12), $y, 440 - (int) (($y - 240) * 0.12), $y, $dark);
                }

                imagesetthickness($image, 10);
                imagearc($image, 300, 240, 200, 140, 180, 360, $dark);
            })(),
            'cushion' => (function () use ($image, $main, $dark): void {
                imagefilledpolygon($image, [150, 160, 300, 185, 450, 160, 425, 300, 450, 440, 300, 415, 150, 440, 175, 300], $main);
                imagesetthickness($image, 3);
                imageline($image, 175, 300, 425, 300, $dark);
            })(),
            'throw' => (function () use ($image, $main, $dark, $light): void {
                foreach ([[400, 460, $dark], [330, 400, $main], [260, 340, $light], [190, 270, $main]] as [$top, $bottom, $fill]) {
                    imagefilledrectangle($image, 140, $top, 460, $bottom, $fill);
                }
            })(),
            'towel' => (function () use ($image, $main, $light): void {
                imagefilledrectangle($image, 180, 130, 420, 480, $light);

                for ($y = 160; $y < 480; $y += 70) {
                    imagefilledrectangle($image, 180, $y, 420, $y + 22, $main);
                }
            })(),
            default => imagefilledrectangle($image, 200, 200, 400, 480, $main),
        };

        $temporary = (string) tempnam(sys_get_temp_dir(), 'nonox-demo-');
        $file = "{$temporary}.png";
        rename($temporary, $file);
        imagepng($image, $file);
        imagedestroy($image);

        return $file;
    }
}
