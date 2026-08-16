<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Shared driver-detection logic for turning a stored attachment
 * (disk + path) into a URL something outside this app can actually fetch.
 * Originally lived only in MediaResource::mediaUrl() (Sprint 2 API
 * Hardening) — extracted so InstagramProvider::publishPost() can reuse the
 * exact same "which disks need a signed temporaryUrl() vs a plain public
 * url()" decision instead of re-deriving it. Meta's Instagram Content
 * Publishing API (image_url/video_url) has the same requirement MediaResource
 * was built for: a real, fetchable-without-auth URL, not a disk-relative
 * path a private bucket would 403 on.
 */
class PublicMediaUrlResolver
{
    public static function resolve(string $disk, string $path, int $expiresInMinutes = 10): string
    {
        $diskConfig = config("filesystems.disks.{$disk}", []);
        $driver = $diskConfig['driver'] ?? null;
        $isPublic = ($diskConfig['visibility'] ?? 'private') === 'public';

        $isServedPrivateLocalDisk = $driver === 'local'
            && ($diskConfig['serve'] ?? false)
            && ! $isPublic;

        $isPrivateS3CompatibleDisk = $driver === 's3' && ! $isPublic;

        $storageDisk = Storage::disk($disk);

        return $isServedPrivateLocalDisk || $isPrivateS3CompatibleDisk
            ? $storageDisk->temporaryUrl($path, now()->addMinutes($expiresInMinutes))
            : $storageDisk->url($path);
    }
}
