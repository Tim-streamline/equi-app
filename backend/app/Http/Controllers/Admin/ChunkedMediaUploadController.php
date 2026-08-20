<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryItem;
use App\Models\MediaAsset;
use App\Support\ChunkedMediaUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChunkedMediaUploadController extends Controller
{
    public function __construct(private readonly ChunkedMediaUpload $uploads) {}

    public function store(Request $request): Response
    {
        $length = $this->integerHeader($request, 'Upload-Length');
        $libraryItemId = $request->header('X-Library-Item-Id') ?: null;

        if ($libraryItemId !== null && ! LibraryItem::whereKey($libraryItemId)->exists()) {
            abort(422, 'The selected library item does not exist.');
        }

        $metadata = $this->uploads->start((string) $request->user('admin')->id, $libraryItemId, $length);

        return response((string) $metadata['id'], 200, ['Content-Type' => 'text/plain']);
    }

    public function update(Request $request, string $upload): Response
    {
        $offset = $this->integerHeader($request, 'Upload-Offset', allowZero: true);
        $length = $this->integerHeader($request, 'Upload-Length');
        $input = $request->getContent(true);
        abort_unless(is_resource($input), 400, 'Missing upload chunk.');

        $result = $this->uploads->append(
            $upload,
            (string) $request->user('admin')->id,
            $offset,
            $length,
            $request->header('Upload-Name'),
            $input,
        );

        $currentOffset = (int) $result['metadata']['offset'];

        return response('', $result['conflict'] ? 409 : 204, ['Upload-Offset' => (string) $currentOffset]);
    }

    public function offset(Request $request, string $upload): Response
    {
        $metadata = $this->ownedUpload($request, $upload);

        return response('', 200, ['Upload-Offset' => (string) $metadata['offset']]);
    }

    public function asset(Request $request, string $upload): JsonResponse
    {
        $metadata = $this->ownedUpload($request, $upload);
        abort_unless($metadata['status'] === 'complete' && $metadata['asset_id'], 409, 'Upload is not complete.');

        return response()->json(['asset' => MediaAsset::findOrFail($metadata['asset_id'])]);
    }

    public function destroy(Request $request): Response
    {
        $upload = trim((string) $request->getContent());
        $metadata = $this->ownedUpload($request, $upload);
        $this->uploads->revert($metadata);

        return response('', 200);
    }

    /** @return array<string, mixed> */
    private function ownedUpload(Request $request, string $upload): array
    {
        $metadata = $this->uploads->find($upload);
        abort_unless(
            $metadata && hash_equals((string) $metadata['owner_id'], (string) $request->user('admin')->id),
            404,
        );

        return $metadata;
    }

    private function integerHeader(Request $request, string $name, bool $allowZero = false): int
    {
        $value = $request->header($name);
        abort_unless(is_string($value) && preg_match('/^\d+$/', $value), 400, "Missing or invalid {$name} header.");

        $integer = (int) $value;
        abort_unless($allowZero ? $integer >= 0 : $integer > 0, 400, "Invalid {$name} header.");

        return $integer;
    }
}
