<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class PublishNowPostRequest extends FormRequest
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
            'social_page_ids' => ['nullable', 'array'],
            'social_page_ids.*' => ['integer', 'exists:social_pages,id'],
        ];
    }
}
