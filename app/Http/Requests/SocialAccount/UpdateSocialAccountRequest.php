<?php

namespace App\Http\Requests\SocialAccount;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialAccountRequest extends FormRequest
{
    // Code-quality review (2026-08-17), item A1/5.1: must run the real
    // 'update' policy check here, not defer to the controller — Laravel
    // resolves a FormRequest's authorize()/validation during dependency
    // resolution, before the controller method body (and any
    // $this->authorize() call written inside it) ever runs. Deferring to
    // `true` would let an unauthorized caller's request body be validated
    // (and see a 422 for an invalid body) before ever being told they
    // lack permission at all — see AuthorizesTargetUserCapability's own
    // docblock for the exact regression this class of bug caused during
    // this refactor.
    public function authorize(): bool
    {
        $socialAccount = $this->route('socialAccount');

        return $socialAccount instanceof SocialAccount
            && $this->user()?->can('update', $socialAccount) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_username' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'token_expires_at' => ['nullable', 'date'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['connected', 'expired', 'revoked', 'failed', 'pending'])],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
