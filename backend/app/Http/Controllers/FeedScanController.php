<?php

namespace App\Http\Controllers;

use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ScanResult;
use App\Support\AuditLogger;
use App\Support\FeedCatalog;
use App\Support\FeedScanProcessor;
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
        $data = $request->validate(['client_id' => 'required|uuid', 'name' => 'required|string|max:255']);
        $user = $request->attributes->get('powersync_user_id');
        // Lock user to serialize retries before the unique idempotency record exists.
        $s = DB::transaction(function () use ($data, $user) {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            if ($existing = FeedScanSubmission::where('user_id', $user)->where('client_id', $data['client_id'])->first()) {
                return $existing;
            }
            $product = Product::create(['brand' => 'Nog te controleren', 'name' => $data['name'], 'needs_review' => true]);
            $product->forceFill(['catalog_status' => 'draft'])->save();
            $version = FeedCatalog::productDraft($product, ['brand' => $product->brand, 'name' => $product->name, 'category' => null, 'ingredients' => [], 'nutrients' => [], 'composition_text' => null], null);
            $s = FeedScanSubmission::create(['user_id' => $user, 'client_id' => $data['client_id'], 'product_id' => $product->id, 'product_version_id' => $version->id]);
            AuditLogger::log('feed_scan_submitted', $product, after: ['submission_id' => $s->id, 'user_id' => $user]);

            return $s;
        });

        return response()->json($s);
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->owned($request, $id));
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
                abort_unless(in_array($s->status, ['draft', 'failed']), 409, 'Deze inzending wordt al verwerkt.');
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
                $a = FeedLabelAttachment::create(['product_version_id' => $s->product_version_id, 'submission_id' => $s->id,
                    'submitted_by' => $s->user_id, 'purpose' => in_array($data['slot'], ['composition', 'analysis']) ? $data['slot'] : 'packaging',
                    'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'checksum' => $checksum]);
                $map[$data['slot']] = $a->id;
                $s->update(['photo_ids' => $map, 'status' => 'draft', 'message' => null, 'error_code' => null]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return response()->json($s->fresh());
    }

    public function analyse(Request $request, string $id)
    {
        return response()->json(app(FeedScanProcessor::class)->analyse($this->owned($request, $id)));
    }

    public function scans(Request $request)
    {
        $user = $request->attributes->get('powersync_user_id');

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
