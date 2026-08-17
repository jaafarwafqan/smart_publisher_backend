<?php

namespace App\Http\Requests\SocialAccount;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;

class SelectSocialPagesRequest extends FormRequest
{
    // See UpdateSocialAccountRequest::authorize()'s docblock for why this
    // runs the real 'selectPages' policy check here rather than deferring
    // to the controller — this is the exact case
    // SocialAccountOrganizationAuthorizationTest caught (expected 403, got
    // 422) before this fix.
    public function authorize(): bool
    {
        $socialAccount = $this->route('socialAccount');

        return $socialAccount instanceof SocialAccount
            && $this->user()?->can('selectPages', $socialAccount) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page_ids' => ['required', 'array'],
            'page_ids.*' => ['integer', 'exists:social_pages,id'],
        ];
    }
}
