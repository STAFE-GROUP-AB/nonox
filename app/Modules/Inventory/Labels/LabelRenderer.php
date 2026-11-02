<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

use App\Modules\Inventory\Models\LabelTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders labels to a PDF with dompdf (ADR-002): each page is the template's
 * page (A4 for a sheet, the label itself on a roll), each label is placed at
 * the sheet margin plus its pitch, and each element at its box. Remote
 * resources are off; barcodes, QR codes and the logo are inline data URIs.
 * The layout is computed once per distinct label and reused for its copies.
 */
final class LabelRenderer
{
    /** The most labels in one PDF. */
    public const int MAX_LABELS = 5000;

    /**
     * @param  list<LabelData>  $labels  one entry per printed label (copies repeat the same object)
     * @param  int  $startAt  the first free position on the first sheet (1-based; sheets only)
     */
    public function html(LabelTemplate $template, array $labels, int $startAt = 1, ?string $logoUri = null): string
    {
        $perPage = $template->perPage();
        $slot = $template->media === LabelMedia::Sheet ? max(0, min($perPage - 1, $startAt - 1)) : 0;
        $layouts = [];
        $pages = [];
        $page = [];

        foreach ($labels as $label) {
            $key = spl_object_id($label);
            $layouts[$key] ??= LabelLayoutEngine::layout($template, $label, $logoUri)['boxes'];
            [$x, $y] = $template->origin($slot);
            $page[] = [$x, $y, $layouts[$key]];
            $slot++;

            if ($slot >= $perPage) {
                $pages[] = $page;
                $page = [];
                $slot = 0;
            }
        }

        if ($page !== [] || $pages === []) {
            $pages[] = $page;
        }

        [$pageWidth, $pageHeight] = $template->pageSize();

        return view('inventory::pdf.labels', [
            'pages' => $pages,
            'pageWidth' => $pageWidth,
            'pageHeight' => $pageHeight,
            'labelWidth' => $template->width_mm,
            'labelHeight' => $template->height_mm,
            'guides' => $template->media === LabelMedia::Sheet && $template->cutting_guides,
        ])->render();
    }

    /**
     * @param  list<LabelData>  $labels
     * @return array{pdf: string, pages: int}
     */
    public function pdf(LabelTemplate $template, array $labels, int $startAt = 1, ?string $logoUri = null): array
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        [$pageWidth, $pageHeight] = $template->pageSize();
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($template, $labels, $startAt, $logoUri));
        $pdf->setPaper([0, 0, $pageWidth * 72 / 25.4, $pageHeight * 72 / 25.4]);
        $pdf->render();

        return ['pdf' => (string) $pdf->output(), 'pages' => $pdf->getCanvas()->get_page_count()];
    }
}
