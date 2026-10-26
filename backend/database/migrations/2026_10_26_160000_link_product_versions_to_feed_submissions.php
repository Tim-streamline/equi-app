<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->foreignUuid('submission_id')->nullable()->index()->constrained('feed_scan_submissions')->nullOnDelete();
        });

        // Previous drafts and admin revisions retain their submission's photos.
        // Only assign ownership where that evidence identifies one submission.
        $owners = DB::table('feed_label_attachments')->whereNotNull('product_version_id')->whereNotNull('submission_id')
            ->select('product_version_id')->selectRaw('MIN(CAST(submission_id AS TEXT)) AS submission_id')
            ->groupBy('product_version_id')->havingRaw('COUNT(DISTINCT submission_id) = 1')->get();
        foreach ($owners as $owner) {
            DB::table('product_versions')->where('id', $owner->product_version_id)->update(['submission_id' => $owner->submission_id]);
        }

        // Reopen only provably untouched submissions stopped by the old global
        // latest-version guard. A user must explicitly resume; no AI is started.
        $blocked = DB::table('feed_scan_submissions as s')->join('product_versions as v', 'v.id', '=', 's.product_version_id')
            ->where('s.status', 'review_required')->whereColumn('v.submission_id', 's.id')
            ->select('s.id', 's.product_id', 's.workflow_version')->get();
        foreach ($blocked as $submission) {
            $versions = DB::table('product_versions as v')->where('v.product_id', $submission->product_id)
                ->where(function ($query) use ($submission) {
                    $query->where('v.submission_id', $submission->id)->orWhereExists(function ($photos) use ($submission) {
                        $photos->selectRaw('1')->from('feed_label_attachments as a')->whereColumn('a.product_version_id', 'v.id')->where('a.submission_id', $submission->id);
                    });
                });
            $reviewed = $versions->where(function ($query) {
                $query->whereNotNull('v.created_by')->orWhereNotNull('v.reviewed_by')->orWhere('v.status', '!=', 'draft')
                    ->orWhereExists(function ($photos) {
                        $photos->selectRaw('1')->from('feed_label_attachments as a')->whereColumn('a.product_version_id', 'v.id')->whereNotNull('a.uploaded_by');
                    });
            })->exists();
            if (! $reviewed) {
                DB::table('feed_scan_submissions')->where('id', $submission->id)->update([
                    'status' => $submission->workflow_version === 2 ? 'needs_photos' : 'draft',
                    'processing_token' => null, 'processing_started_at' => null,
                    'message' => 'Je foto’s zijn ontvangen. Je kunt de analyse opnieuw starten vanuit je scanhistorie.',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('product_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('submission_id'));
    }
};
