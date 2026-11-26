<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/** Renders admin PDF downloads: A4, no remote/PHP/JS content, with a page-number footer. */
class PdfDocument
{
    /**
     * @param  array  $options  Extra Dompdf options, e.g. a chroot for local images.
     * @param  callable|null  $loaded  Inspects the parsed document before rendering; may throw.
     */
    public static function render(string $html, array $options = [], ?callable $loaded = null): string
    {
        $pdf = new Dompdf(new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans',
            'tempDir' => storage_path('framework/cache'), 'fontCache' => storage_path('framework/cache'),
            ...$options,
        ]));
        $pdf->loadHtml($html, 'UTF-8');
        if ($loaded) {
            $loaded($pdf);
        }
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(485, 805, '{PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }

    /** A private, non-cached attachment response for a rendered PDF. */
    public static function download(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
