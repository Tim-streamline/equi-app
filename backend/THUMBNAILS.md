# Library thumbnails

Video uploads use FFmpeg to select a representative frame from the first second and save a 640 × 480 JPEG on the public disk. Both direct and resumable uploads run this bounded step before returning the completed asset. No queue worker is required. Failed extraction is logged and leaves the media upload usable with a UI placeholder.

FFmpeg must be installed on the application server and callable by PHP. Sail's PHP 8.5 image already includes it. Set `FFMPEG_BINARY` to an absolute path if it is not on `PATH`. `PUBLIC_STORAGE_URL` controls image and video URLs.

After deployment run the migrations, then `php artisan library:thumbnails` to fill in missing thumbnails for existing local uploads. The command is repeatable and preserves manually selected covers. External video URLs are not downloaded and show the fallback unless a manual cover is uploaded.

The existing `hero_image_url` field remains the public/mobile cover field. `thumbnail_mode` distinguishes automatic selection, manual upload and intentional removal. Save associates uploads made while creating a new item, saves its cover and categories in one database transaction. Removing a cover does not delete the shared media file.
