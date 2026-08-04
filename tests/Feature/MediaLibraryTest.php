<?php

namespace Tests\Feature;

use App\Models\MediaAttachment;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_is_reachable_and_filters_by_search_and_tags(): void
    {
        Storage::fake('local');
        $user = $this->actingUser();

        $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('sunset-beach.jpg', 100, 100),
            'tags' => ['vacation', 'beach'],
        ])->assertCreated();

        $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('office-desk.jpg', 100, 100),
            'tags' => ['work'],
        ])->assertCreated();

        $response = $this->getJson('/api/v1/media');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));

        $searchResponse = $this->getJson('/api/v1/media?search=sunset');
        $this->assertCount(1, $searchResponse->json('data'));
        $this->assertSame('sunset-beach.jpg', $searchResponse->json('data.0.meta.original_name'));

        $tagResponse = $this->getJson('/api/v1/media?tags[]=work');
        $this->assertCount(1, $tagResponse->json('data'));
        $this->assertSame('office-desk.jpg', $tagResponse->json('data.0.meta.original_name'));
    }

    public function test_store_computes_a_real_content_hash_and_flags_a_duplicate_on_re_upload(): void
    {
        Storage::fake('local');
        $this->actingUser();

        $first = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ]);
        $first->assertCreated();
        $this->assertNull($first->json('data.duplicate_of_id'));

        $firstAttachment = MediaAttachment::query()->firstOrFail();
        $this->assertNotEmpty($firstAttachment->content_hash);

        $second = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('photo-copy.jpg', 200, 200),
        ]);
        $second->assertCreated();
        $this->assertSame($firstAttachment->id, $second->json('data.duplicate_of_id'));
    }

    public function test_compress_shrinks_a_real_image_and_updates_the_record(): void
    {
        Storage::fake('local');
        $this->actingUser();

        $upload = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('large.jpg', 3000, 2000),
        ]);
        $upload->assertCreated();
        $attachmentId = $upload->json('data.id');
        $originalSize = $upload->json('data.size');

        $response = $this->postJson("/api/v1/media/{$attachmentId}/compress");

        $response->assertOk();
        $this->assertLessThanOrEqual(1920, $response->json('data.width'));
        $this->assertLessThan($originalSize, $response->json('data.size'));
        $this->assertTrue($response->json('data.meta.compressed'));
    }

    public function test_compress_honestly_rejects_video_instead_of_faking_it(): void
    {
        Storage::fake('local');
        $this->actingUser();

        $upload = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ]);
        $upload->assertCreated();
        $attachmentId = $upload->json('data.id');

        $response = $this->postJson("/api/v1/media/{$attachmentId}/compress");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Compression is only available for images right now; video needs a queued transcode job, not yet implemented.');
    }

    public function test_editor_sees_and_mutates_only_own_media_in_the_selected_organization(): void
    {
        Storage::fake('local');
        $organizationOwner = User::factory()->create();
        $editor = User::factory()->create();

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organizationOwner->current_organization_id,
                'user_id' => $editor->id,
            ],
            ['role' => 'editor', 'status' => 'active'],
        );

        $ownersAttachment = $this->asOrganizationOf($organizationOwner, fn (): MediaAttachment => MediaAttachment::query()->create([
            'user_id' => $organizationOwner->id,
            'type' => 'image',
            'collection' => 'default',
            'disk' => 'public',
            'path' => 'media/owner-only.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
            'meta' => ['original_name' => 'owner-only.jpg'],
            'tags' => [],
        ]));

        Sanctum::actingAs($editor);
        $headers = ['X-Organization-Id' => (string) $organizationOwner->current_organization_id];

        $this->withHeaders($headers)->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('editor-owned.jpg', 100, 100),
        ])->assertCreated();

        $this->withHeaders($headers)->postJson("/api/v1/media/{$ownersAttachment->id}/compress")
            ->assertForbidden();
    }

    /**
     * Sprint 2 (API Hardening): uploads previously always landed on the
     * 'public' disk (hardcoded), served as a permanently public static
     * file via the storage:link symlink with zero authentication — the
     * exact URL MediaResource handed back to the uploader. Now on the
     * private 'local' disk, the returned URL is signed and time-limited
     * (Laravel's own storage.local route, ServeFile), and — critically —
     * the SAME file at its real path is refused without that signature.
     * Deliberately does not Storage::fake() here: the framework's
     * storage.local route was registered against the real disk config at
     * boot, and this proves the real, currently-running behavior end to
     * end rather than a faked approximation of it.
     */
    public function test_a_freshly_uploaded_files_url_is_signed_and_the_raw_path_is_refused_without_it(): void
    {
        $this->actingUser();

        $upload = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('confidential.jpg', 50, 50),
        ]);
        $upload->assertCreated();

        $attachment = MediaAttachment::query()->firstOrFail();
        $this->assertSame('local', $attachment->disk, 'new uploads must land on the private disk, not public');

        $signedUrl = $upload->json('data.url');
        $this->assertStringContainsString('signature=', $signedUrl);

        $signedParts = parse_url($signedUrl);
        $signedRequestUri = $signedParts['path'].'?'.$signedParts['query'];

        // No Sanctum::actingAs at all for these two — this is the raw
        // framework storage route, not an API endpoint the app guards.
        $this->app['auth']->forgetGuards();

        $this->get($signedRequestUri)->assertOk();
        $this->get($signedParts['path'])->assertForbidden();

        Storage::disk('local')->delete($attachment->path);
    }
}
