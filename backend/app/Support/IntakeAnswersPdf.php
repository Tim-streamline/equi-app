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
        if (! $review['sections']) {
            throw new RuntimeException('De intakevragen zijn niet beschikbaar voor de PDF.');
        }
        $images = [];
        $files = IntakeAttachment::where('booking_id', $booking->id)->get()->keyBy('id');
        foreach ($review['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['type'] !== 'photo') {
                    continue;
                }
                foreach ($row['attachments'] as $attachment) {
                    $file = $files->get($attachment['id']);
                    if ($file && in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                        if (! Storage::disk('local')->exists($file->path)) {
                            throw new RuntimeException('Een intakefoto ontbreekt. De PDF is niet verstuurd.');
                        }
                        $images[$file->id] = 'data:'.$file->mime.';base64,'.base64_encode(Storage::disk('local')->get($file->path));
                    }
                }
            }
        }

        return view('intake.answers-pdf', ['booking' => $booking, 'sections' => $review['sections'], 'images' => $images])->render();
    }

    public function render(IntakeBooking $booking): string
    {
        $pdf = new Dompdf(new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'allowedProtocols' => ['data://'], 'defaultFont' => 'DejaVu Sans',
            'tempDir' => storage_path('framework/cache'), 'fontCache' => storage_path('framework/cache'),
        ]));
        $pdf->loadHtml($this->html($booking), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(485, 805, '{PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }
}
