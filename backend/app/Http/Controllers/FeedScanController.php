<?php

namespace App\Http\Controllers;

use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ScanResult;
use App\Support\AuditLogger;
use App\Support\FeedScanProcessor;
use App\Support\FeedScanRecognition;
use App\Support\FeedScanReviewCompletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FeedScanController extends Controller
{
    private function owned(Request $request, string $id): FeedScanSubmission
    {
        return FeedScanSubmission::where('user_id', $request->attributes->get('powersync_user_id'))->findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['client_id' => 'required|uuid', 'name' => 'nullable|string|max:255', 'reference_product_id' => 'nullable|uuid']);
        $user = $request->attributes->get('powersync_user_id');
        // Lock user to serialize retries before the unique idempotency record exists.
        $s = DB::transaction(function () use ($data, $user) {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            if ($existing = FeedScanSubmission::where('user_id', $user)->where('client_id', $data['client_id'])->first()) {
                return $existing;
            }
            $reference = ! empty($data['reference_product_id']) ? Product::whereNotIn('catalog_status', ['archived', 'removed'])->findOrFail($data['reference_product_id']) : null;
            $s = FeedScanSubmission::create(['user_id' => $user, 'client_id' => $data['client_id'], 'submitted_name' => $data['name'] ?? null,
                'workflow_version' => 2, 'status' => 'awaiting_composition', 'reference_product_id' => $reference?->id]);
            AuditLogger::log('feed_scan_submitted', $s, after: ['submission_id' => $s->id, 'user_id' => $user]);

            return $s;
        });

        return response()->json($s->fresh());
    }

    public function show(Request $request, string $id)
    {
        return response()->json(app(FeedScanReviewCompletion::class)->complete($this->owned($request, $id)));
    }

    public function upload(Request $request, string $id)
    {
        $s = $this->owned($request, $id);
        $data = $request->validate(['slot' => ['required', 'regex:/^(composition|analysis|front|extra-[0-9]+)$/'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('feed-scanner.max_photo_kb')]]);
        $path = null;
        try {
            DB::transaction(function () use ($s, $data, &$path) {
                $s = FeedScanSubmission::lockForUpdate()->findOrFail($s->id);
                abort_unless(in_array($s->status, ['draft', 'failed', 'awaiting_composition', 'needs_photos']), 409, 'Deze inzending wordt al verwerkt.');
                if ($s->workflow_version === 2 && $s->status === 'awaiting_composition' && $data['slot'] !== 'composition') {
                    throw ValidationException::withMessages(['file' => 'Controleer eerst de samenstelling.']);
                }
                $file = $data['file'];
                $checksum = hash_file('sha256', $file->getRealPath());
                $current = isset($s->photo_ids[$data['slot']]) ? FeedLabelAttachment::find($s->photo_ids[$data['slot']]) : null;
                if ($current?->checksum === $checksum) {
                    return;
                }
                // Includes replaced files to bound cumulative private storage per submission.
                $attachments = FeedLabelAttachment::where('submission_id', $s->id)->get();
                if ($attachments->count() >= 20 || $attachments->sum('size_bytes') + $file->getSize() > config('feed-scanner.max_total_bytes')) {
                    throw ValidationException::withMessages(['file' => 'De foto’s zijn samen te groot. Gebruik kleinere foto’s (maximaal 20 MB samen).']);
                }
                $map = $s->photo_ids;
                if (! isset($map[$data['slot']]) && count($map) >= config('feed-scanner.max_photos')) {
                    abort(422, 'Maximaal tien foto’s per inzending.');
                }
                $path = $file->store('feed-labels', 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['file' => 'De foto kon niet worden opgeslagen. Probeer opnieuw.']);
                }
                $a = FeedLabelAttachment::create(['product_version_id' => null, 'submission_id' => $s->id,
                    'submitted_by' => $s->user_id, 'purpose' => in_array($data['slot'], ['composition', 'analysis']) ? $data['slot'] : 'packaging',
                    'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'checksum' => $checksum]);
                $map[$data['slot']] = $a->id;
                $s->update(['photo_ids' => $map, 'status' => $s->workflow_version === 2 ? ($s->product_id ? 'needs_photos' : 'awaiting_composition') : 'draft', 'error_code' => null]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return response()->json($s->fresh());
    }

    public function photo(Request $request, string $id, string $photo)
    {
        $submission = $this->owned($request, $id);
        abort_unless(in_array($photo, array_values($submission->photo_ids ?? []), true), 404);
        $attachment = FeedLabelAttachment::where('submission_id', $submission->id)->findOrFail($photo);
        abort_unless(in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, null, [
            'Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function analyse(Request $request, string $id)
    {
        $data = $request->validate(['name' => 'nullable|string|max:255']);
        $submission = $this->owned($request, $id);
        if (filled($data['name'] ?? null)) {
            FeedScanSubmission::whereKey($submission->id)->whereIn('status', ['draft', 'needs_photos', 'failed'])
                ->update(['submitted_name' => trim($data['name'])]);
        }

        return response()->json(app(FeedScanProcessor::class)->analyse($submission));
    }

    public function check(Request $request, string $id)
    {
        return response()->json(app(FeedScanRecognition::class)->check($this->owned($request, $id)));
    }

    public function confirm(Request $request, string $id)
    {
        return response()->json(app(FeedScanRecognition::class)->confirm($this->owned($request, $id)));
    }

    public function morePhotos(Request $request, string $id)
    {
        $data = $request->validate(['changed' => 'required|boolean']);

        return response()->json(app(FeedScanRecognition::class)->morePhotos($this->owned($request, $id), $data['changed']));
    }

    public function scans(Request $request)
    {
        $user = $request->attributes->get('powersync_user_id');

        $pending = FeedScanSubmission::where('user_id', $user)->with('product:id,name,brand')->where('status', '!=', 'completed')->orderByDesc('created_at')->limit(20)->get();
        foreach ($pending as $submission) {
            app(FeedScanReviewCompletion::class)->complete($submission);
        }

        return response()->json(['scans' => ScanResult::where('user_id', $user)->orderByDesc('scanned_at')->limit(100)->get(),
            'pending' => FeedScanSubmission::where('user_id', $user)->with('product:id,name,brand')->where('status', '!=', 'completed')->orderByDesc('created_at')->limit(20)->get()]);
    }

    public function result(Request $request, string $id)
    {
        $scan = ScanResult::where('user_id', $request->attributes->get('powersync_user_id'))->findOrFail($id);
        $s = FeedScanSubmission::where('scan_result_id', $scan->id)->first();

        return response()->json(['scan' => $scan, 'result' => $s?->public_result]);
    }
}
