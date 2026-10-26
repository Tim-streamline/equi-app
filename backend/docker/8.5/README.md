# Sail runtime with local scanner OCR

Published from Laravel Sail's PHP 8.5 runtime. The only runtime change is installing `tesseract-ocr`, `tesseract-ocr-nld` and `tesseract-ocr-eng` alongside its existing Ubuntu packages. The upstream MIT license is included.

`compose.yaml` points to this directory so a clean `sail build` retains the OCR dependencies. When upgrading Sail, compare these four runtime files with `vendor/laravel/sail/runtimes/8.5` and retain the three OCR packages. This is the normal application container; there is no separate OCR server or public OCR endpoint.
