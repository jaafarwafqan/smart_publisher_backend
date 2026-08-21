<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /admin/organizations/{organization}/subscription — reverts an
 * organization to the Free plan, applying Free's limits immediately. reason
 * is mandatory, same as every other subscription-mutating admin action —
 * see AdminSubscriptionController::revert().
 */
class RevertOrganizationSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
