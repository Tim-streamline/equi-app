<?php

namespace App\Support;

use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Answers-only document for the authenticated admin download. */
class IntakeAnswersPdf
{
    public function filename(IntakeBooking $booking): string
    {
        $booking->loadMissing('horse');
        $horse = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii($booking->intakeHorseName())), '_-') ?: 'paard';
        $date = $booking->submitted_at?->copy()->timezone('Europe/Amsterdam')->format('d-m-Y') ?? 'datum-onbekend';

        return 'EquiApp_protocolintake_'.$date.'_'.mb_substr($horse, 0, 80).'.pdf';
    }

    public function html(IntakeBooking $booking): string
    {
        $booking->loadMissing('horse', 'user');
        $review = app(IntakeReview::class)->forBooking($booking);
        $sections = [...$review['sections'], ...array_map(fn ($section) => [...$section, 'title' => 'Eerder ingevuld · '.$section['title']], $review['historical_sections'] ?? [])];
        if (! $review['sections']) {
            throw new RuntimeException('De intakevragen zijn niet beschikbaar voor de PDF.');
        }
        $images = [];
        $files = IntakeAttachment::where('booking_id', $booking->id)->get()->keyBy('id');
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if (! in_array($row['type'], ['photo', 'file'], true)) {
                    continue;
                }
                foreach ($row['attachments'] as $attachment) {
                    $file = $files->get($attachment['id']);
                    if ($file && in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                        if (! Storage::disk('local')->exists($file->path)) {
                            throw new RuntimeException('Een intakefoto ontbreekt. De PDF is niet verstuurd.');
                        }
                        // Large base64 attributes can exceed parser limits, silently
                        // losing the image and everything that follows in the intake.
                        // Resolve only this booking's approved image references on the private disk.
                        $images[$file->id] = 'file:///'.ltrim(str_replace('\\', '/', Storage::disk('local')->path($file->path)), '/');
                    }
                }
            }
        }

        return view('intake.answers-pdf', ['booking' => $booking, 'sections' => $sections, 'images' => $images])->render();
    }

    public function render(IntakeBooking $booking): string
    {
        $pdf = new Dompdf(new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'allowedProtocols' => ['file://'], 'chroot' => Storage::disk('local')->path(''), 'defaultFont' => 'DejaVu Sans',
            'tempDir' => storage_path('framework/cache'), 'fontCache' => storage_path('framework/cache'),
        ]));
        $pdf->loadHtml($this->html($booking), 'UTF-8');
        if (! $pdf->getDom()->getElementById('intake-document-complete')) {
            throw new RuntimeException('De PDF is onvolledig opgebouwd. Probeer opnieuw of neem contact op met de beheerder.');
        }
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(485, 805, '{PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }
}
