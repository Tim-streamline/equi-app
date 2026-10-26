<?php

namespace Tests\Feature;

use App\Models\FeedLabelAttachment;
use App\Support\FeedCompositionMatcher;
use App\Support\FeedCompositionOcr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class FeedCompositionOcrTest extends TestCase
{
    public function test_real_local_ocr_can_read_a_complete_composition_without_a_provider(): void
    {
        if (! (new ExecutableFinder)->find('tesseract')) {
            $this->markTestSkipped('Installeer Tesseract om de echte OCR-integratietest uit te voeren.');
        }
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (! is_file($font)) {
            $font = '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf';
        }
        if (! is_file($font)) {
            $this->markTestSkipped('Een TrueType-testlettertype is nodig.');
        }
        Storage::fake('local');
        $image = imagecreatetruecolor(1800, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 0, 0, 0);
        foreach (['Testmerk Balansvoer', 'Samenstelling:', 'Timotheegras, luzerne, lijnzaad, calciumcarbonaat, zout.', 'Analytische bestanddelen', 'Ruw eiwit 11 procent'] as $i => $line) {
            imagettftext($image, 32, 0, 35, 65 + $i * 80, $ink, $font, $line);
        }
        $path = Storage::disk('local')->path('composition.png');
        imagepng($image, $path);
        imagedestroy($image);
        $photo = new FeedLabelAttachment(['disk' => 'local', 'path' => 'composition.png']);
        $read = app(FeedCompositionOcr::class)->read($photo);
        $this->assertStringContainsString('Timotheegras', $read['text']);
        $this->assertNotNull(FeedCompositionMatcher::section($read['text']));
        $this->assertGreaterThanOrEqual(90, $read['confidence']);
    }
}
