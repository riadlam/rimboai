<?php

namespace App\Services;

use App\Support\PublicMediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Store lab media references locally + on fal CDN for inference.
 */
class MediaReferenceStorage
{
    public function __construct(private FalService $fal) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function storeMany(int $userId, array $files, string $role = 'reference'): array
    {
        if ($files !== [] && ! $this->fal->configured()) {
            throw new RuntimeException('Media service is not configured.');
        }

        $assets = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $mime = (string) $file->getMimeType();
            $kind = str_starts_with($mime, 'video/')
                ? 'video'
                : (str_starts_with($mime, 'audio/') ? 'audio' : 'image');

            $ext = strtolower($file->guessExtension() ?: ($kind === 'video' ? 'mp4' : ($kind === 'audio' ? 'mp3' : 'jpg')));
            $filename = Str::uuid()->toString().'.'.$ext;
            $path = $file->storeAs("lab/references/{$userId}", $filename, 'public');
            $localUrl = PublicMediaUrl::storagePath($path);
            $falUrl = $this->fal->uploadToCdn($file);

            $assets[] = [
                'url' => $falUrl,
                'fal_url' => $falUrl,
                'local_url' => $localUrl,
                'path' => $path,
                'type' => $kind,
                'role' => $role,
                'content_type' => $mime,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        }

        return $assets;
    }
}
