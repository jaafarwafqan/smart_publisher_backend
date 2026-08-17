<?php

namespace App\Http\Requests\SocialAccount;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetSocialAccountStatusRequest extends FormRequest
{
    // See UpdateSocialAccountRequest::authorize()'s docblock for why this
    // runs the real 'changeStatus' policy check here rather than deferring
    // to the controller.
    public function authorize(): bool
    {
        $socialAccount = $this->route('socialAccount');

        return $socialAccount instanceof SocialAccount
            && $this->user()?->can('changeStatus', $socialAccount) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['connected', 'expired', 'revoked', 'failed', 'pending'])],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
