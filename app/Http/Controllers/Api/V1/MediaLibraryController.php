<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\MediaAttachment;
use App\Models\Post;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class MediaLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MediaAttachment::class);

        $user = $request->user();
        $query = MediaAttachment::query();

        // A member who only has posts.view_own (editor) must not receive
        // another member's media merely because it lives in the same tenant.
        if (! $user->hasOrganizationPermission(
            app(TenantContext::class)->get(),
            OrganizationPermission::PostsViewAll,
        )) {
            $query->where('user_id', (int) $user->id);
        }

        if ($request->filled('collection')) {
            $query->where('collection', $request->string('collection')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where('meta->original_name', 'like', "%{$search}%");
        }

        if ($request->filled('tags')) {
            $tags = (array) $request->input('tags');
            $query->where(function ($tagQuery) use ($tags) {
                foreach ($tags as $tag) {
                    $tagQuery->orWhereJsonContains('tags', $tag);
                }
            });
        }

        $attachments = $query->latest()->paginate(30);

        return response()->json([
            'data' => MediaResource::collection($attachments->getCollection())->resolve(),
            'meta' => [
                'current_page' => $attachments->currentPage(),
                'last_page' => $attachments->lastPage(),
                'per_page' => $attachments->perPage(),
                'total' => $attachments->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MediaAttachment::class);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf,doc,docx,xls,xlsx,csv,zip',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm,'
                .'application/pdf,application/msword,'
                .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                .'application/vnd.ms-excel,'
                .'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                .'text/csv,text/plain,application/zip,application/octet-stream',
                // Mirrors MediaValidation on the Flutter side (media_engine/validation):
                // 20MB for images, 500MB for video. Documents get a flat 50MB cap.
                function (string $attribute, UploadedFile $value, \Closure $fail): void {
                    $mimeType = (string) $value->getMimeType();
                    $isVideo = str_starts_with($mimeType, 'video/');
                    $isImage = str_starts_with($mimeType, 'image/');
                    $maxKb = $isVideo ? 512000 : ($isImage ? 20480 : 51200);
                    if ($value->getSize() > $maxKb * 1024) {
                        $fail("The {$attribute} must not be greater than {$maxKb} kilobytes.");
                    }
                },
            ],
            'collection' => ['nullable', 'string', 'max:100'],
            'post_id' => ['nullable', 'integer', 'exists:posts,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        if (isset($validated['post_id'])) {
            $post = Post::query()->findOrFail($validated['post_id']);
            $this->authorize('update', $post);
        }

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $collection = $validated['collection'] ?? 'default';
        $disk = 'public';
        $folder = 'media/'.date('Y/m');

        // Computed before store() moves the file — a temp upload path is only
        // guaranteed valid until then.
        $contentHash = hash_file('sha256', $file->getRealPath()) ?: null;

        $storedPath = $file->store($folder, $disk);
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();
        $type = match (true) {
            Str::startsWith((string) $mimeType, 'video/') => 'video',
            Str::startsWith((string) $mimeType, 'image/') => 'image',
            default => 'document',
        };

        $thumbnailPath = $this->createThumbnailPlaceholder($disk, $storedPath, $type);

        $userId = (int) $request->user()->id;

        // Informational only — an identical re-upload still succeeds; the
        // caller decides what to do with the hint, never a silent block.
        $duplicateOf = $contentHash
            ? MediaAttachment::query()
                ->where('user_id', $userId)
                ->where('content_hash', $contentHash)
                ->first()
            : null;

        $attachment = MediaAttachment::query()->create([
            'post_id' => $validated['post_id'] ?? null,
            'user_id' => $userId,
            'type' => $type,
            'collection' => $collection,
            'disk' => $disk,
            'path' => $storedPath,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mimeType,
            'size' => (int) $file->getSize(),
            'tags' => $validated['tags'] ?? [],
            'content_hash' => $contentHash,
            'meta' => [
                'original_name' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
            ],
        ]);

        return response()->json([
            'message' => 'Media uploaded successfully.',
            'data' => [
                ...(new MediaResource($attachment))->resolve(),
                'duplicate_of_id' => $duplicateOf?->id,
            ],
        ], 201);
    }

    public function attachToPost(Request $request, Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        $validated = $request->validate([
            'attachment_ids' => ['required', 'array', 'min:1'],
            'attachment_ids.*' => ['integer', 'distinct', 'exists:media_attachments,id'],
        ]);

        $attachmentIds = $validated['attachment_ids'];
        $attachments = MediaAttachment::query()
            ->whereIn('id', $attachmentIds)
            ->get();

        if ($attachments->count() !== count($attachmentIds)) {
            abort(404, 'One or more media attachments were not found in this organization.');
        }

        foreach ($attachments as $attachment) {
            $this->authorize('update', $attachment);
        }

        $attachments->each->update(['post_id' => $post->id]);

        return response()->json([
            'message' => 'Media attached to post successfully.',
            'data' => MediaResource::collection($post->fresh()->load('mediaAttachments')->mediaAttachments)->resolve(),
        ]);
    }

    public function compress(Request $request, MediaAttachment $mediaAttachment): JsonResponse
    {
        $this->authorize('update', $mediaAttachment);

        if ($mediaAttachment->type !== 'image') {
            return response()->json([
                'message' => 'Compression is only available for images right now; video needs a queued transcode job, not yet implemented.',
            ], 422);
        }

        $absolutePath = Storage::disk($mediaAttachment->disk)->path($mediaAttachment->path);

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->decodePath($absolutePath);
            // Only shrinks if larger than this — never upscales a smaller image.
            $image->scaleDown(1920, 1920);
            $image->save($absolutePath, quality: 75);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to compress image: '.$e->getMessage()], 422);
        }

        $mediaAttachment->update([
            'size' => (int) File::size($absolutePath),
            'width' => $image->width(),
            'height' => $image->height(),
            'meta' => [...($mediaAttachment->meta ?? []), 'compressed' => true],
        ]);

        return response()->json([
            'message' => 'Media compressed successfully.',
            'data' => (new MediaResource($mediaAttachment->fresh()))->resolve(),
        ]);
    }

    public function detachFromPost(Request $request, Post $post, MediaAttachment $mediaAttachment): JsonResponse
    {
        $this->authorize('delete', $mediaAttachment);

        if ($mediaAttachment->post_id !== $post->id) {
            return response()->json([
                'message' => 'Attachment does not belong to this post.',
            ], 422);
        }

        $mediaAttachment->update(['post_id' => null]);

        return response()->json([
            'message' => 'Media detached from post successfully.',
            'data' => (new MediaResource($mediaAttachment->fresh()))->resolve(),
        ]);
    }

    public function destroy(Request $request, MediaAttachment $mediaAttachment): JsonResponse
    {
        $this->authorize('delete', $mediaAttachment);

        Storage::disk($mediaAttachment->disk)->delete($mediaAttachment->path);

        if ($mediaAttachment->thumbnail_path) {
            Storage::disk($mediaAttachment->disk)->delete($mediaAttachment->thumbnail_path);
        }

        $mediaAttachment->delete();

        return response()->json([
            'message' => 'Media deleted successfully.',
        ]);
    }

    private function createThumbnailPlaceholder(string $disk, string $path, string $type): ?string
    {
        if (! in_array($type, ['image', 'video'], true)) {
            return null;
        }

        $thumbnailPath = preg_replace('/\.[^.\/]+$/', '_thumb.jpg', $path, 1);

        if (! $thumbnailPath) {
            return null;
        }

        if ($type === 'image') {
            $absoluteSourcePath = Storage::disk($disk)->path($path);
            $absoluteThumbPath = Storage::disk($disk)->path($thumbnailPath);

            File::ensureDirectoryExists(dirname($absoluteThumbPath));

            try {
                $manager = new ImageManager(new Driver);
                $image = $manager->decodePath($absoluteSourcePath);
                $image->cover(480, 270);
                $image->save($absoluteThumbPath, 80);
            } catch (\Throwable) {
                return null;
            }

            return $thumbnailPath;
        }

        $absoluteSourcePath = Storage::disk($disk)->path($path);
        $absoluteThumbPath = Storage::disk($disk)->path($thumbnailPath);

        File::ensureDirectoryExists(dirname($absoluteThumbPath));

        $ffmpegCheck = Process::run('where ffmpeg');

        if ($ffmpegCheck->successful()) {
            $command = sprintf(
                'ffmpeg -y -i "%s" -ss 00:00:01 -vframes 1 "%s"',
                $absoluteSourcePath,
                $absoluteThumbPath
            );

            $result = Process::run($command);

            if ($result->successful() && File::exists($absoluteThumbPath)) {
                return $thumbnailPath;
            }
        }

        Storage::disk($disk)->put($thumbnailPath, 'video-thumbnail-pending');

        return $thumbnailPath;
    }
}
