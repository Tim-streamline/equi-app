<?php

return [
    'ocr_binary' => env('FEED_SCANNER_OCR_BINARY', 'tesseract'),
    'ocr_languages' => env('FEED_SCANNER_OCR_LANGUAGES', 'nld+eng'),
    'ocr_timeout' => 25,
    'api_key' => env('FEED_SCANNER_OPENAI_API_KEY'),
    'model' => env('FEED_SCANNER_MODEL', 'gpt-4.1'),
    'timeout' => 60,
    'max_photos' => 10,
    'max_photo_kb' => 8192,
    'max_total_bytes' => 20 * 1024 * 1024,
];
