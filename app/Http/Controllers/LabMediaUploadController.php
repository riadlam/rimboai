<?php

namespace App\Http\Controllers;

use App\Services\FalService;
use App\Services\MediaReferenceStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Upload a single lab reference file to fal CDN (avoids giant multipart generate requests).
 */
class LabMediaUploadController extends Controller
{
    public function store(Request $request, MediaReferenceStorage $mediaStorage, FalService $fal): JsonResponse
    {
        if (! $fal->configured()) {
            return response()->json(['message' => 'Media service is not configured.'], 503);
        }

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > 0 && $request->all() === [] && $request->allFiles() === []) {
            return response()->json([
                'message' => 'Upload blocked by server size limit (post_max_size / upload_max_filesize). Try a smaller file (under ~35MB each).',
            ], 422);
        }

        $file = $request->file('file');
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            $code = $file->getError();
            Log::warning('Lab media PHP upload error', [
                'upload_error' => $code,
                'upload_message' => $file->getErrorMessage(),
                'name' => $file->getClientOriginalName(),
                'size_client' => $file->getSize(),
                'content_length' => $contentLength,
            ]);

            return response()->json([
                'message' => $this->uploadErrorMessage($code, $file->getClientOriginalName()),
                'upload_error' => $code,
            ], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        if (! $this->isAllowedMedia($file)) {
            return response()->json([
                'message' => 'Unsupported media type. Use JPG/PNG/WebP, MP4/WebM/MOV, or MP3/WAV.',
            ], 422);
        }

        try {
            $assets = $mediaStorage->storeMany((int) $request->user()->id, [$file], 'reference');
            $asset = $assets[0] ?? null;
            if ($asset === null) {
                return response()->json(['message' => 'Could not store media file.'], 502);
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not upload media to CDN.',
            ], 502);
        }

        return response()->json([
            'url' => $asset['fal_url'] ?? $asset['url'],
            'fal_url' => $asset['fal_url'] ?? $asset['url'],
            'local_url' => $asset['local_url'] ?? null,
            'type' => $asset['type'] ?? 'image',
            'original_name' => $asset['original_name'] ?? $file->getClientOriginalName(),
            'size' => $asset['size'] ?? $file->getSize(),
        ], 201);
    }

    private function isAllowedMedia(UploadedFile $file): bool
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $name = strtolower((string) $file->getClientOriginalName());
        $mime = strtolower((string) ($file->getMimeType() ?: ''));

        $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $videoExt = ['mp4', 'webm', 'mov', 'm4v', 'qt'];
        $audioExt = ['mp3', 'wav', 'mpeg', 'mpga', 'm4a', 'aac', 'ogg'];

        foreach (array_merge($imageExt, $videoExt, $audioExt) as $allowed) {
            if ($ext === $allowed || str_ends_with($name, '.'.$allowed)) {
                return true;
            }
        }

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/');
    }

    private function uploadErrorMessage(int $code, ?string $name = null): string
    {
        $label = $name ? "“{$name}”" : 'This file';

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "{$label} is too large for the server upload limit (max ~40MB per file). Compress it or use a shorter clip.",
            UPLOAD_ERR_PARTIAL => "{$label} was only partially uploaded. Try again on a stable connection.",
            UPLOAD_ERR_NO_FILE => 'No file was received. Please choose a file and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing. Ask hosting to fix PHP upload_tmp_dir.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the upload to disk. Check disk space / temp permissions.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked this upload. Try a smaller file or different format.',
            default => "{$label} failed to upload (PHP error code {$code}).",
        };
    }
}
