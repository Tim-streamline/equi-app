<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLOTS = ['composition' => 'ingredients', 'analysis' => 'nutrition', 'front' => 'product'];

    public function up(): void
    {
        Schema::table('feed_scan_submissions', function (Blueprint $table) {
            foreach (self::SLOTS as $prefix) {
                $prefix .= '_photo_';
                $table->uuid($prefix.'id')->nullable();
                foreach (['disk', 'path', 'original_name', 'mime_type'] as $field) {
                    $table->string($prefix.$field)->nullable();
                }
                $table->string($prefix.'checksum', 64)->nullable();
                $table->unsignedBigInteger($prefix.'size_bytes')->nullable();
                $table->timestampTz($prefix.'uploaded_at')->nullable();
                $table->text($prefix.'ocr_text')->nullable();
                $table->double($prefix.'ocr_confidence')->nullable();
                $table->unsignedInteger($prefix.'ocr_words')->nullable();
                $table->unsignedInteger($prefix.'ocr_low_confidence_words')->nullable();
                $table->string($prefix.'ocr_engine')->nullable();
                $table->string($prefix.'ocr_status')->nullable();
                $table->timestampTz($prefix.'ocr_processed_at')->nullable();
            }
            $table->unsignedInteger('photo_upload_count')->default(0);
            $table->unsignedBigInteger('photo_uploaded_bytes')->default(0);
            foreach (['decision', 'name', 'brand'] as $field) {
                $table->string('recognition_'.$field)->nullable();
            }
            foreach (['photo_id', 'product_id', 'version_id'] as $field) {
                $table->uuid('recognition_'.$field)->nullable();
            }
            $table->text('recognition_composition_text')->nullable();
            $table->text('recognition_reference_composition')->nullable();
            $table->boolean('recognition_owner_reported_change')->default(false);
        });
        $this->backfill();
    }

    public function backfill(): void
    {
        // Keep legacy JSON and attachment rows intact. No files are moved or deleted.
        DB::table('feed_scan_submissions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                if ($row->ingredients_photo_id || $row->nutrition_photo_id || $row->product_photo_id) {
                    continue;
                }
                $ids = json_decode($row->photo_ids ?? '{}', true) ?: [];
                $recognition = json_decode($row->recognition ?? 'null', true) ?: [];
                $files = DB::table('feed_label_attachments')->where('submission_id', $row->id)->get()->keyBy('id');
                $uniqueFiles = $files->unique(fn ($file) => $file->disk.':'.$file->path);
                $data = ['photo_upload_count' => $uniqueFiles->count(), 'photo_uploaded_bytes' => $uniqueFiles->sum('size_bytes')];
                foreach (self::SLOTS as $slot => $prefix) {
                    // Use only the primary slot; extra-* photos are intentionally excluded.
                    $file = $files->get($ids[$slot] ?? '');
                    if (! $file || $file->purpose !== ($slot === 'front' ? 'packaging' : $slot)) {
                        continue;
                    }
                    foreach (['id', 'disk', 'path', 'original_name', 'mime_type', 'checksum', 'size_bytes'] as $field) {
                        $data[$prefix.'_photo_'.$field] = $file->$field;
                    }
                    $data[$prefix.'_photo_uploaded_at'] = $file->created_at;
                }
                // Old recognition can refer to a replaced photo. Never transfer it to its replacement.
                if (isset($data['ingredients_photo_id']) && ($recognition['photo_id'] ?? null) === $data['ingredients_photo_id']) {
                    foreach (['decision', 'photo_id', 'product_id', 'version_id', 'name', 'brand', 'composition_text', 'reference_composition'] as $field) {
                        $data['recognition_'.$field] = $recognition[$field] ?? null;
                    }
                    $data['recognition_owner_reported_change'] = $recognition['owner_reported_change'] ?? false;
                    if (isset($recognition['ocr'])) {
                        foreach (['text', 'confidence', 'words', 'low_confidence_words', 'engine'] as $field) {
                            $data['ingredients_photo_ocr_'.$field] = $recognition['ocr'][$field] ?? null;
                        }
                        $data['ingredients_photo_ocr_status'] = ($recognition['ocr']['engine'] ?? '') === 'unavailable' ? 'failed' : 'completed';
                    }
                }
                DB::table('feed_scan_submissions')->where('id', $row->id)->update($data);
            }
        });
    }

    public function down(): void
    {
        // Rolling back would discard photos uploaded after this migration.
        throw new RuntimeException('Submission photo columns contain live evidence; restore a database backup to roll back.');
    }
};
