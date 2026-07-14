<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageReferenceStorage
{
    public function __construct(private FalService $fal) {}

    /**
     * Store references locally for our records and on fal CDN for inference.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function storeMany(int $userId, array $files): array
    {
        if ($files !== [] && ! $this->fal->configured()) {
            throw new RuntimeException('Image service is not configured.');
        }

        $assets = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $ext = strtolower($file->guessExtension() ?: 'jpg');
            $filename = Str::uuid()->toString().'.'.$ext;
            $path = $file->storeAs("lab/references/{$userId}", $filename, 'public');
            $localUrl = Storage::disk('public')->url($path);

            // fal must fetch references from the public internet — localhost URLs fail.
            $falUrl = $this->fal->uploadToCdn($file);

            $assets[] = [
                'url' => $falUrl,
                'fal_url' => $falUrl,
                'local_url' => $localUrl,
                'path' => $path,
                'type' => 'image',
                'role' => 'reference',
                'content_type' => $file->getMimeType(),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        }

        return $assets;
    }
}
