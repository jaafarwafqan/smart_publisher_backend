<?php

namespace App\Http\Requests\SocialAccount;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;

class AddSocialPageRequest extends FormRequest
{
    // See UpdateSocialAccountRequest::authorize()'s docblock for why this
    // runs the real 'syncPages' policy check here rather than deferring to
    // the controller.
    public function authorize(): bool
    {
        $socialAccount = $this->route('socialAccount');

        return $socialAccount instanceof SocialAccount
            && $this->user()?->can('syncPages', $socialAccount) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
        ];
    }
}
