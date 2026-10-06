<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function presign(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($request->user()->canPerform('documents.manage'), 403);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'content_type' => ['required', 'in:'.implode(',', config('uploads.allowed_mimes'))],
            'original_name' => ['required', 'string', 'max:255'],
        ]);
        $maxBytes = config('uploads.document_max_mb') * 1024 * 1024;
        $disk = $this->disk();
        $path = $this->path($context, $request, $data['content_type']);
        $direct = $disk === 's3' && filled(config('filesystems.disks.s3.bucket'));

        if (! $direct) {
            return response()->json(['data' => compact('path', 'disk') + ['max_bytes' => $maxBytes, 'direct_upload' => false]]);
        }

        $upload = Storage::disk($disk)->temporaryUploadUrl($path, now()->addMinutes(15), ['ContentType' => $data['content_type']]);

        return response()->json(['data' => [
            'direct_upload' => true,
            'url' => is_array($upload) ? $upload['url'] : $upload,
            'headers' => is_array($upload) ? ($upload['headers'] ?? []) : [],
            'path' => $path,
            'disk' => $disk,
            'max_bytes' => $maxBytes,
        ]]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($request->user()->canPerform('documents.manage'), 403);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'max:'.(config('uploads.document_max_mb') * 1024), 'mimetypes:'.implode(',', config('uploads.allowed_mimes'))],
        ]);
        $file = $data['file'];
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $disk = $this->disk();
        $path = $this->path($context, $request, $mime);
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), ['visibility' => 'private']);
        $document = Document::query()->create([
            'documentable_type' => 'temporary', 'documentable_id' => $request->user()->id,
            'category' => $data['category'], 'disk' => $disk, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime,
            'size_bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $document], 201);
    }

    public function complete(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($request->user()->canPerform('documents.manage'), 403);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:80'], 'path' => ['required', 'string', 'max:500'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'in:'.implode(',', config('uploads.allowed_mimes'))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(config('uploads.document_max_mb') * 1024 * 1024)],
        ]);
        $prefix = sprintf('tenants/%s/temp/%s/', $context->tenant()->id, $request->user()->id);
        abort_unless(str_starts_with($data['path'], $prefix), 403, 'This upload does not belong to you.');
        $disk = $this->disk();
        abort_unless(Storage::disk($disk)->exists($data['path']), 422, 'The uploaded file could not be found.');
        $document = Document::query()->create($data + [
            'documentable_type' => 'temporary', 'documentable_id' => $request->user()->id,
            'disk' => $disk, 'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $document], 201);
    }

    public function download(Request $request, string $document): StreamedResponse
    {
        abort_unless($request->user()->canPerform('documents.view'), 403);
        $document = Document::query()->where('documentable_type', '!=', 'temporary')->findOrFail($document);
        AuditLog::query()->create([
            'user_id' => $request->user()->id, 'action' => 'document.downloaded', 'entity_type' => Document::class,
            'entity_id' => $document->id, 'new_values' => ['category' => $document->category],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        abort_unless($request->user()->canPerform('documents.manage'), 403);
        $document = Document::query()->findOrFail($document);
        AuditLog::query()->create([
            'user_id' => $request->user()->id, 'action' => 'document.deleted', 'entity_type' => Document::class,
            'entity_id' => $document->id, 'old_values' => $document->only(['category', 'original_name', 'mime_type', 'size_bytes']),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        return response()->json(['deleted' => true]);
    }

    private function disk(): string
    {
        return config('filesystems.default') === 's3' && filled(config('filesystems.disks.s3.bucket')) ? 's3' : 'local';
    }

    private function path(TenantContext $context, Request $request, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', default => 'bin',
        };

        return sprintf('tenants/%s/temp/%s/%s.%s', $context->tenant()->id, $request->user()->id, Str::uuid(), $extension);
    }
}
