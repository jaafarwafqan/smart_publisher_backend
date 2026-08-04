<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'meta' => ['nullable', 'array'],
            'target_page_ids' => ['nullable', 'array'],
            'target_page_ids.*' => ['integer', 'exists:social_pages,id'],
        ];
    }
}
