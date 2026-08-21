<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller checks PostsCreate against the current organization
        // membership. Keeping validation here prevents validation drift while
        // leaving tenant authorization at the actual trust boundary.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'meta' => ['nullable', 'array'],
            'meta.rich_content' => ['nullable', 'array', 'max:5000'],
            'target_page_ids' => ['nullable', 'array'],
            'target_page_ids.*' => ['integer', 'exists:social_pages,id'],
        ];
    }
}
