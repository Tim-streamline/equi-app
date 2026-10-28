<?php

namespace Tests\Unit;

use App\Rules\IntakePhotoDimensions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class IntakePhotoDimensionsTest extends TestCase
{
    public function test_upload_resolution_limit_accepts_both_orientations_and_rejects_either_excess_edge(): void
    {
        foreach ([[7680, 4320, true], [4320, 7680, true], [6000, 4000, true],
            [7681, 4320, false], [4320, 7681, false], [7680, 4321, false], [4321, 7680, false],
            [5000, 5000, false], [16000, 4000, false]] as [$width, $height, $allowed]) {
            // A real JPEG with dimensions substituted in its SOF header: the
            // validation rule must inspect metadata, not allocate pixel buffers.
            $file = UploadedFile::fake()->image('photo.jpg', 16, 16);
            $bytes = file_get_contents($file->getPathname());
            $offset = strpos($bytes, "\xff\xc0");
            $this->assertNotFalse($offset);
            file_put_contents($file->getPathname(), substr_replace($bytes, pack('nn', $height, $width), $offset + 5, 4));
            $validator = Validator::make(['file' => $file], ['file' => ['image', new IntakePhotoDimensions]]);
            $this->assertSame($allowed, $validator->passes(), $width.'x'.$height);
            if (! $allowed) {
                $this->assertStringContainsString('7680 × 4320', $validator->errors()->first('file'));
            }
        }
    }
}
