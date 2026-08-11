<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Root-cause fix for the untranslated-messages bug report (2026-08-10): the
 * API had no locale awareness at all — every error message was hardcoded
 * English regardless of the requesting client's language. This asserts the
 * new Accept-Language-driven behavior (SetLocaleFromHeaderMiddleware +
 * lang/{ar,en}/{api,validation}.php) without changing the existing
 * default-English contract ValidationCoverageTest already locks in.
 */
class LocalizedApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_failure_is_english_by_default_with_no_accept_language_header(): void
    {
        // Only 'password' is actually required by AuthController::login()'s
        // rules — 'email' is nullable (username is the alternative
        // identifier), so an empty body fails on 'password', not 'email'.
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.password.0', 'The password field is required.');
    }

    public function test_validation_failure_is_arabic_when_the_client_requests_arabic(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'فشل التحقق من صحة البيانات')
            ->assertJsonPath('errors.password.0', 'حقل password مطلوب.');
    }

    public function test_a_realistic_browser_style_accept_language_header_still_resolves_to_arabic(): void
    {
        // e.g. "ar-IQ,ar;q=0.9,en;q=0.8" — only the first entry's primary
        // subtag matters (see SetLocaleFromHeaderMiddleware::resolveLocale).
        $this->withHeaders(['Accept-Language' => 'ar-IQ,ar;q=0.9,en;q=0.8'])
            ->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'فشل التحقق من صحة البيانات');
    }

    public function test_an_unsupported_language_falls_back_to_english_not_a_missing_translation(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr-FR'])
            ->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_unauthenticated_message_is_arabic_when_requested(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/organizations')
            ->assertStatus(401)
            ->assertJsonPath('message', 'يجب تسجيل الدخول أولاً.');
    }

    public function test_not_found_message_is_arabic_when_requested(): void
    {
        $admin = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'users.view', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('users.view');
        Sanctum::actingAs($admin);

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');
    }
}
