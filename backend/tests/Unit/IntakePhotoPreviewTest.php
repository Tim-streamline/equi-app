<?php

namespace Tests\Unit;

use App\Http\Controllers\IntakeAttachmentController;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Support\IntakePhotoPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntakePhotoPreviewTest extends TestCase
{
    private function attachment(): IntakeAttachment
    {
        Storage::fake('intake-preview-tests');
        Storage::set('local', Storage::disk('intake-preview-tests'));
        $image = imagecreatetruecolor(2400, 1600);
        imagefill($image, 0, 0, imagecolorallocate($image, 25, 120, 210));
        ob_start();
        imagejpeg($image);
        Storage::disk('local')->put('intake/test/photo.jpg', ob_get_clean());
        $attachment = new IntakeAttachment(['path' => 'intake/test/photo.jpg', 'name' => 'photo.jpg', 'mime' => 'image/jpeg']);
        $booking = new IntakeBooking(['user_id' => 'owner']);
        $booking->setRelation('horse', new Horse(['owner_id' => 'owner']));
        $attachment->setRelation('booking', $booking);

        return $attachment;
    }

    private function request(string $query, string $owner = 'owner'): Request
    {
        $request = Request::create('/api/intake-media/test?'.$query);
        $request->attributes->set('powersync_user_id', $owner);

        return $request;
    }

    public function test_private_thumbnail_is_cached_and_original_download_is_unchanged(): void
    {
        $attachment = $this->attachment();
        $disk = Storage::disk('local');
        $original = $disk->get($attachment->path);
        $controller = new IntakeAttachmentController;
        $response = $controller->show($this->request('preview=1'), $attachment);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('1', $response->headers->get('X-Intake-Preview'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $path = IntakePhotoPreview::path($attachment->path);
        $size = getimagesize($disk->path($path));
        $this->assertSame([960, 640], array_slice($size, 0, 2));
        $this->assertSame($original, $disk->get($attachment->path));
        // A cache hit does not decode the original again.
        $disk->put($attachment->path, 'invalid image');
        $this->assertSame($path, app(IntakePhotoPreview::class)->make($attachment->path));
        $disk->put($attachment->path, $original);
        $download = $controller->show($this->request('preview=1&download=1'), $attachment);
        ob_start();
        $download->sendContent();
        $this->assertSame($original, ob_get_clean());
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_thumbnail_access_is_denied_before_generation_for_wrong_owner_or_transferred_horse(): void
    {
        $attachment = $this->attachment();
        foreach (['other', 'owner'] as $user) {
            if ($user === 'owner') {
                $attachment->booking->horse->owner_id = 'new-owner';
            }
            try {
                (new IntakeAttachmentController)->show($this->request('preview=1', $user), $attachment);
                $this->fail('Expected access denial');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            Storage::disk('local')->assertMissing(IntakePhotoPreview::path($attachment->path));
        }
    }

    public function test_non_images_are_rejected_without_a_cached_file(): void
    {
        $attachment = $this->attachment();
        Storage::disk('local')->put($attachment->path, '%PDF-1.4');
        $this->expectException(HttpException::class);
        app(IntakePhotoPreview::class)->make($attachment->path);
    }

    public function test_all_exif_orientations_are_preserved_without_rotating_the_original(): void
    {
        $attachment = $this->attachment();
        $image = imagecreatetruecolor(80, 60);
        $colors = [[230, 10, 10], [10, 230, 10], [10, 10, 230], [230, 230, 10]];
        foreach ($colors as $i => $color) {
            $x = ($i % 2) * 40;
            $y = intdiv($i, 2) * 30;
            imagefilledrectangle($image, $x, $y, $x + 39, $y + 29, imagecolorallocate($image, ...$color));
        }
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        $corners = [[0, 1, 2, 3], [1, 0, 3, 2], [3, 2, 1, 0], [2, 3, 0, 1],
            [0, 2, 1, 3], [2, 0, 3, 1], [3, 1, 2, 0], [1, 3, 0, 2]];
        foreach ($corners as $index => $expected) {
            $orientation = $index + 1;
            $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x112, 3, 1, $orientation)."\0\0".pack('V', 0);
            $original = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
            $path = 'intake/test/orientation-'.$orientation.'.jpg';
            Storage::disk('local')->put($path, $original);
            $preview = app(IntakePhotoPreview::class)->make($path);
            $result = imagecreatefromjpeg(Storage::disk('local')->path($preview));
            $width = imagesx($result);
            $height = imagesy($result);
            $this->assertSame($orientation >= 5 ? [60, 80] : [80, 60], [$width, $height]);
            foreach ($expected as $corner => $colorIndex) {
                $rgb = imagecolorat($result, $corner % 2 ? $width - 10 : 10, $corner >= 2 ? $height - 10 : 10);
                $actual = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                foreach ($colors[$colorIndex] as $channel => $value) {
                    $this->assertLessThan(12, abs($value - $actual[$channel]), 'Orientation '.$orientation);
                }
            }
            $this->assertSame($original, Storage::disk('local')->get($path));
        }
    }

    public function test_oversized_pixel_dimensions_are_rejected_before_decoding(): void
    {
        $attachment = $this->attachment();
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        Storage::disk('local')->put($attachment->path, substr_replace($png, pack('NN', 100000, 100000), 16, 8));
        try {
            app(IntakePhotoPreview::class)->make($attachment->path);
            $this->fail('Expected pixel limit rejection');
        } catch (HttpException $error) {
            $this->assertSame(413, $error->getStatusCode());
        }
        Storage::disk('local')->assertMissing(IntakePhotoPreview::path($attachment->path));
    }
    public function test_transparent_png_and_webp_are_flattened_on_white(): void
    {
        $this->attachment();
        foreach (['png', 'webp'] as $format) {
            $image = imagecreatetruecolor(40, 60);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            ob_start();
            ('image'.$format)($image);
            $original = ob_get_clean();
            $path = 'intake/test/transparent.'.$format;
            Storage::disk('local')->put($path, $original);
            $preview = app(IntakePhotoPreview::class)->make($path);
            $result = imagecreatefromjpeg(Storage::disk('local')->path($preview));
            $this->assertSame([40, 60], [imagesx($result), imagesy($result)]);
            $this->assertSame(0xffffff, imagecolorat($result, 20, 30));
            $this->assertSame($original, Storage::disk('local')->get($path));
        }
    }

    public function test_maximum_upload_resolution_decodes_in_both_orientations(): void
    {
        $attachment = $this->attachment();
        foreach ([[7680, 4320, 960, 540], [4320, 7680, 540, 960]] as [$width, $height, $previewWidth, $previewHeight]) {
            $image = imagecreatetruecolor($width, $height);
            $path = 'intake/test/boundary-'.$width.'.jpg';
            imagejpeg($image, Storage::disk('local')->path($path), 85);
            unset($image);
            $original = hash_file('sha256', Storage::disk('local')->path($path));
            $preview = app(IntakePhotoPreview::class)->make($path);
            $this->assertSame([$previewWidth, $previewHeight], array_slice(getimagesize(Storage::disk('local')->path($preview)), 0, 2));
            $this->assertSame($original, hash_file('sha256', Storage::disk('local')->path($path)));
        }
    }

}
