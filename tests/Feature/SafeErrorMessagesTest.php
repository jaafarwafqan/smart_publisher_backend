<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 2 (API Hardening): a 404 from implicit route-model binding
 * (Post::class $post route parameters etc.) previously leaked the raw
 * Laravel/Eloquent exception message — "No query results for model
 * [App\Models\Post] 999999" — verbatim to every API caller, exposing the
 * real internal PHP namespace/class path. bootstrap/app.php now rewrites
 * that one specific case (NotFoundHttpException wrapping a
 * ModelNotFoundException) to a generic message, without touching the
 * legitimate developer-authored `abort(404, '...')` calls elsewhere.
 */
class SafeErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_model_via_route_binding_never_leaks_the_internal_class_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/posts/999999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Resource not found.',
        ]);
        $this->assertStringNotContainsString('App\\Models\\Post', $response->getContent());
        $this->assertStringNotContainsString('No query results for model', $response->getContent());
    }

    public function test_a_developer_authored_404_message_is_preserved(): void
    {
        // Regression guard for the deliberate scope boundary: this fix must
        // stay narrow to ModelNotFoundException-wrapped 404s. An explicit
        // abort(404, '...') elsewhere in the app
        // (SocialAccountController::authorizeTargetUserCapability, hit here
        // via a real user who exists but belongs to a different
        // organization) carries an intentional, safe message and must not
        // be rewritten to the generic one.
        $actor = User::factory()->create();
        $otherOrgUser = User::factory()->create();
        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/v1/users/'.$otherOrgUser->id.'/social-accounts');

        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'The requested user is not a member of the current organization.',
        ]);
        $this->assertStringNotContainsString('No query results for model', $response->getContent());
    }
}
