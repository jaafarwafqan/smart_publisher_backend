<?php

namespace App\Http\Requests\SocialAccount;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Requests\SocialAccount\Concerns\AuthorizesTargetUserCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOAuthCallbackRequest extends FormRequest
{
    use AuthorizesTargetUserCapability;

    public function authorize(): bool
    {
        return $this->authorizeTargetUserCapability(OrganizationPermission::SocialAccountsConnect);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(SocialAccountController::PROVIDERS)],
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
        ];
    }
}
