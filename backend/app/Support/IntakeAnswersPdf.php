<?php

namespace App\Support;

use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
        // Current answers first; answers to questions that are now hidden or archived follow under their own heading.
        $sections = [...array_filter($review['sections'], fn ($section) => $section['rows']),
            ...array_map(fn ($section) => [...$section, 'title' => 'Eerder ingevuld · '.$section['title']], $review['historical_sections'] ?? [])];
        if (! $review['sections']) {
            throw new RuntimeException('De intakevragen zijn niet beschikbaar voor de PDF.');
        }
        $images = [];
        $previews = app(IntakePhotoPreview::class);
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
                        if (! empty($row['not_applicable'])) {
                            // The PDF prints only "Niet van toepassing" for this row.
                            continue;
                        }
                        // Dompdf keeps every embedded image in memory, so full-size originals
                        // (up to 15 MB each) exhaust PHP memory on photo-heavy intakes. Embed
                        // the cached downscaled copy; originals stay downloadable in the admin.
                        try {
                            $path = $previews->make($file->path);
                        } catch (Throwable $error) {
                            // One unreadable photo must not block the whole export; the PDF names it instead.
                            // Filesystem warnings surface as ErrorException, not as the helper's HTTP aborts.
                            Log::warning('Intake photo omitted from PDF', ['attachment_id' => $file->id,
                                'reason' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : $error::class.': '.$error->getMessage()]);
                            $images[$file->id] = null;

                            continue;
                        }
                        // Large base64 attributes can exceed parser limits, silently
                        // losing the image and everything that follows in the intake.
                        // Resolve only this booking's approved image references on the private disk.
                        $images[$file->id] = 'file:///'.ltrim(str_replace('\\', '/', Storage::disk('local')->path($path)), '/');
                    }
                }
            }
        }

        return view('intake.answers-pdf', ['booking' => $booking, 'sections' => $sections, 'images' => $images])->render();
    }

    public function render(IntakeBooking $booking): string
    {
        return PdfDocument::render($this->html($booking),
            ['allowedProtocols' => ['file://'], 'chroot' => Storage::disk('local')->path('')],
            function (Dompdf $pdf) {
                if (! $pdf->getDom()->getElementById('intake-document-complete')) {
                    throw new RuntimeException('De PDF is onvolledig opgebouwd. Probeer opnieuw of neem contact op met de beheerder.');
                }
            });
    }
}
