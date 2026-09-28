<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IntakeAttachmentController extends Controller
{
    public function store(Request $request, string $id)
    {
        $userId = $request->attributes->get('powersync_user_id');
        $data = $request->validate(['horse_id' => ['required', 'uuid'], 'section' => ['required', 'string', 'max:64'],
            'field' => ['required', 'string', 'max:64'], 'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360']]);
        abort_unless(Horse::whereKey($data['horse_id'])->where('owner_id', $userId)->exists(), 403);
        $field = IntakeField::where('key', $data['field'])->where('active', true)->whereIn('type', ['photo', 'file'])
            ->whereHas('section', fn ($q) => $q->where('key', $data['section'])->where('active', true)
                ->whereHas('questionnaire', fn ($q) => $q->where('slug', 'protocol-intake')->where('active', true)))->firstOrFail();
        if ($field->type === 'photo') {
            $request->validate(['file' => ['image']]);
        }
        // The offline booking can still be queued for sync when the first file is uploaded.
        $booking = IntakeBooking::unguarded(fn () => IntakeBooking::firstOrCreate(['id' => $id], ['user_id' => $userId, 'horse_id' => $data['horse_id'], 'started_at' => now()]));
        abort_unless($booking->user_id === $userId && $booking->horse_id === $data['horse_id'], 403);
        $file = $request->file('file');
        $path = $file->store('intake/'.$booking->id, 'local');
        try {
            $attachment = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => $data['section'], 'field_key' => $data['field'],
                'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime' => $file->getMimeType()]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['value' => 'attachment:'.$attachment->id.':'.rawurlencode($attachment->name)], 201);
    }

    public function show(Request $request, IntakeAttachment $attachment)
    {
        abort_unless($attachment->booking->user_id === $request->attributes->get('powersync_user_id'), 403);

        return $this->file($request, $attachment);
    }

    public function admin(Request $request, IntakeAttachment $attachment)
    {
        return $this->file($request, $attachment);
    }

    private function file(Request $request, IntakeAttachment $attachment)
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('download')
            ? Storage::disk('local')->download($attachment->path, $attachment->name, $headers)
            : Storage::disk('local')->response($attachment->path, $attachment->name, $headers);
    }
}
