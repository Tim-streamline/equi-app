<?php

namespace App\Support;

use App\Models\FeedLabelAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Local OCR only: no remote provider, credentials or paid inference. */
class FeedCompositionOcr
{
    public function read(FeedLabelAttachment $photo): array
    {
        $path = Storage::disk($photo->disk)->path($photo->path);
        $size = @getimagesize($path);
        if (! $size || $size[0] * $size[1] > 20000000) {
            throw new \RuntimeException('ocr_image_size');
        }
        $process = new Process([config('feed-scanner.ocr_binary'), $path, 'stdout', '-l', config('feed-scanner.ocr_languages'), '--psm', '3', 'tsv'],
            env: ['OMP_THREAD_LIMIT' => '1'], timeout: config('feed-scanner.ocr_timeout'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ocr_unavailable');
        }
        $lines = [];
        $confidence = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            $row = explode("\t", $line, 12);
            if (count($row) !== 12 || $row[0] !== '5' || trim($row[11]) === '') {
                continue;
            }
            $key = implode(':', array_slice($row, 1, 4));
            $lines[$key][] = trim($row[11]);
            $confidence[] = (float) $row[10];
        }

        return ['text' => mb_substr(implode("\n", array_map(fn ($words) => implode(' ', $words), $lines)), 0, 30000),
            'confidence' => count($confidence) ? array_sum($confidence) / count($confidence) : 0,
            'low_confidence_words' => count(array_filter($confidence, fn ($n) => $n < 75)), 'words' => count($confidence), 'engine' => 'tesseract-local-1'];
    }
}
