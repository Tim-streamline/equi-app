<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Support\ProtocolAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IntakeAttachmentController extends Controller
{
    public function store(Request $request, string $id)
    {
        $userId = $request->attributes->get('powersync_user_id');
        $data = $request->validate(['horse_id' => ['required', 'uuid'], 'section' => ['required', 'string', 'max:64'],
            'field' => ['required', 'string', 'max:64'], 'attachment_id' => ['sometimes', 'uuid'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360']]);
        abort_unless(Horse::whereKey($data['horse_id'])->where('owner_id', $userId)->exists(), 403);
        $field = IntakeField::where('key', $data['field'])->where('active', true)->whereIn('type', ['photo', 'file'])
            ->where(fn ($q) => $q->where('answer_section_key', $data['section'])
                ->orWhere(fn ($q) => $q->whereNull('answer_section_key')->whereHas('section', fn ($q) => $q->where('key', $data['section']))))
            ->whereHas('section', fn ($q) => $q->where('active', true)
                ->whereHas('questionnaire', fn ($q) => $q->where('slug', 'protocol-intake')->where('active', true)))->firstOrFail();
        if ($field->type === 'photo') {
            $request->validate(['file' => ['image']]);
        }

        return DB::transaction(function () use ($request, $userId, $data, $id) {
            // Serialize retries for this intake, including a lost successful response.
            app(ProtocolAccess::class)->authorizeBooking($userId, $data['horse_id'], $id);
            $booking = IntakeBooking::findOrFail($id);
            abort_unless($booking->user_id === $userId && $booking->horse_id === $data['horse_id'], 403);
            if (isset($data['attachment_id']) && $existing = IntakeAttachment::find($data['attachment_id'])) {
                abort_unless($existing->booking_id === $booking->id && $existing->section_key === $data['section']
                    && $existing->field_key === $data['field'], 403);

                return response()->json(['value' => 'attachment:'.$existing->id.':'.rawurlencode($existing->name)]);
            }
            $file = $request->file('file');
            $path = $file->store('intake/'.$booking->id, 'local');
            try {
                $attachment = new IntakeAttachment(['booking_id' => $booking->id, 'section_key' => $data['section'], 'field_key' => $data['field'],
                    'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime' => $file->getMimeType()]);
                if (isset($data['attachment_id'])) {
                    $attachment->id = $data['attachment_id'];
                }
                $attachment->save();
            } catch (\Throwable $error) {
                Storage::disk('local')->delete($path);
                throw $error;
            }

            return response()->json(['value' => 'attachment:'.$attachment->id.':'.rawurlencode($attachment->name)], 201);
        });
    }

    public function show(Request $request, IntakeAttachment $attachment)
    {
        abort_unless($attachment->booking->user_id === $request->attributes->get('powersync_user_id')
            && $attachment->booking->horse?->owner_id === $attachment->booking->user_id, 403);

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
