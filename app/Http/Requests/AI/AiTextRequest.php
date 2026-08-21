<?php

namespace App\Http\Requests\AI;

use App\Enums\AiTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:'.((int) config('ai.max_text_characters', 10000))],
            'tone' => ['nullable', Rule::enum(AiTone::class)],
            'target_language' => ['nullable', 'string', 'in:ar,en'],
            'platforms' => ['nullable', 'array', 'max:6'],
            'platforms.*' => ['string', 'in:facebook,instagram,telegram,whatsapp,linkedin,x'],
            // Post lookup is deliberately performed under TenantContext in
            // AiController, rather than an unscoped `exists` rule that could
            // leak whether another organization owns a numeric id.
            'post_id' => ['nullable', 'integer'],
        ];
    }
}
