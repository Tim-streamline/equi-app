<?php

namespace App\Support;

use App\Rules\IntakePhotoDimensions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sentry\Severity;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class IntakeUploadDiagnostics
{
    public function run(Request $request, string $intakeId, Closure $upload)
    {
        $requestId = $this->uuid($request->header('X-Upload-Request-Id')) ?? (string) Str::uuid();
        $tags = array_filter([
            'operation' => 'intake.attachment.upload',
            'upload_request_id' => $requestId,
            // Only the authenticated account is trusted; never take it from form data.
            'account_id' => $this->uuid($request->attributes->get('powersync_user_id')),
            'intake_id' => $this->uuid($intakeId),
            'attachment_id' => $this->uuid($request->input('attachment_id')),
        ], fn ($value) => $value !== null);

        try {
            $response = $upload();
        } catch (Throwable $error) {
            $status = $error instanceof ValidationException ? $error->status
                : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
            $tags['http_status'] = (string) $status;
            // Diagnostics must never replace the original upload error.
            try {
                if ($error instanceof ValidationException) {
                    $tags['validation_failed'] = $this->validationRules($error);
                }
                $tags += $this->fileTags($request);
            } catch (Throwable) {
                $tags['diagnostics_incomplete'] = 'true';
            }
            $this->record($tags, true);
            if ($error instanceof ValidationException) {
                // Preserve Laravel's validation response, adding only safe correlation headers.
                return response()->json(['message' => $error->getMessage(), 'errors' => $error->errors()], $status)
                    ->header('X-Upload-Request-Id', $requestId)
                    ->header('X-Upload-Validation', $tags['validation_failed'] ?? 'unknown');
            }
            throw $error;
        }

        $this->record($tags + ['http_status' => (string) $response->getStatusCode()], false);

        return $response->header('X-Upload-Request-Id', $requestId);
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private function validationRules(ValidationException $error): string
    {
        $failures = [];
        foreach ($error->validator->failed() as $field => $rules) {
            if (! in_array($field, ['horse_id', 'section', 'field', 'attachment_id', 'file'], true)) {
                continue;
            }
            foreach (array_keys($rules) as $rule) {
                $rule = $rule === IntakePhotoDimensions::class ? 'dimensions' : strtolower($rule);
                $rule = in_array($rule, ['required', 'uuid', 'string', 'max', 'file', 'mimes', 'image', 'uploaded', 'dimensions'], true) ? $rule : 'other';
                $failures[] = $field.':'.$rule;
            }
        }

        return implode(',', array_unique($failures)) ?: 'unknown';
    }

    private function fileTags(Request $request): array
    {
        $file = $request->files->get('file');
        $tags = ['file_present' => $file instanceof UploadedFile ? 'true' : 'false'];
        if ($file instanceof UploadedFile) {
            $tags['file_upload_error'] = (string) $file->getError();
            $tags['file_valid'] = $file->isValid() ? 'true' : 'false';
            $tags['client_mime'] = $this->mime($file->getClientMimeType());
            if ($file->isValid()) {
                $tags['file_bytes'] = (string) $file->getSize();
                $tags['detected_mime'] = $this->mime($file->getMimeType());
                $dimensions = @getimagesize($file->getPathname());
                if ($dimensions) {
                    $tags['image_width'] = (string) $dimensions[0];
                    $tags['image_height'] = (string) $dimensions[1];
                }
            }
        }

        return $tags;
    }

    private function mime(?string $value): string
    {
        return in_array($value, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/mpo', 'application/pdf', 'application/octet-stream'], true)
            ? $value : 'other';
    }

    private function record(array $tags, bool $failed): void
    {
        // No filenames, form values, raw exception messages, credentials or image contents.
        try {
            Log::log($failed ? 'warning' : 'info', '[intake-upload] '.($failed ? 'failed' : 'completed'), $tags);
        } catch (Throwable) { /* Logging must not break an upload. */
        }
        if ($failed) {
            try {
                \Sentry\withScope(function (Scope $scope) use ($tags): void {
                    $scope->setTags($tags);
                    \Sentry\captureMessage('Intake attachment upload failed', Severity::warning());
                });
            } catch (Throwable) { /* Telemetry is best effort. */
            }
        }
    }
}
