<?php

namespace App\Http\Requests\SocialAccount;

use App\Enums\OrganizationPermission;
use App\Http\Requests\SocialAccount\Concerns\AuthorizesTargetUserCapability;
use Illuminate\Foundation\Http\FormRequest;

class ConnectTelegramBotRequest extends FormRequest
{
    use AuthorizesTargetUserCapability;

    public function authorize(): bool
    {
        return $this->authorizeTargetUserCapability(OrganizationPermission::SocialAccountsConnect);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'bot_token' => ['required', 'string'],
        ];
    }
}
