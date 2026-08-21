<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiOperation;
use App\Enums\AiTone;
use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\AiTextRequest;
use App\Models\Post;
use App\Services\AI\AIWritingService;
use App\Services\Publishing\PrePublishValidationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function spellCheck(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::SpellCheck);
    }

    public function rewrite(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Rewrite);
    }

    public function improve(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Improve);
    }

    public function shorten(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Shorten);
    }

    public function expand(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Expand);
    }

    public function simplify(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Simplify);
    }

    public function officialNews(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::OfficialNews);
    }

    public function advertisement(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Advertisement);
    }

    public function academicFormat(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::AcademicFormat);
    }

    public function mediaFormat(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::MediaFormat);
    }

    public function suggestTitles(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::SuggestTitles);
    }

    public function suggestClosing(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::SuggestClosing);
    }

    public function suggestCallToAction(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::SuggestCallToAction);
    }

    public function suggestHashtags(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::SuggestHashtags);
    }

    public function addEmojis(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::AddEmojis);
    }

    public function translate(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::Translate);
    }

    public function adaptPlatforms(AiTextRequest $request, AIWritingService $service): JsonResponse
    {
        return $this->respond($request, $service, AiOperation::AdaptPlatforms);
    }

    public function prePublishCheck(Request $request, Post $post, PrePublishValidationService $validator): JsonResponse
    {
        $this->authorize('view', $post);

        return response()->json([
            'data' => $validator->check($post),
        ]);
    }

    private function respond(AiTextRequest $request, AIWritingService $service, AiOperation $operation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $this->authorizeAiUse($user, $request);
        $validated = $request->validated();
        $post = null;

        if (isset($validated['post_id'])) {
            $post = Post::query()->findOrFail((int) $validated['post_id']);
            $this->authorize('update', $post);
        }

        $tone = isset($validated['tone']) ? AiTone::from($validated['tone']) : AiTone::Formal;
        $platforms = array_values($validated['platforms'] ?? []);

        return response()->json([
            'data' => $service->generate(
                $request,
                $user,
                $operation,
                $validated['text'],
                $tone,
                $validated['target_language'] ?? null,
                $platforms,
                $post,
            ),
        ]);
    }

    private function authorizeAiUse(mixed $user, Request $request): void
    {
        $organizationId = app(TenantContext::class)->get();
        abort_unless(
            $user->hasOrganizationPermission($organizationId, OrganizationPermission::PostsCreate),
            403,
            'You do not have permission to use writing assistance in this organization.',
        );
    }
}
