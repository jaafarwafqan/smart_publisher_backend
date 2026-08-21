<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/organizations/{organization}/subscription/trial — grants a
 * trialing period on whatever plan the organization already has (or Free,
 * if it has none yet). Deliberately takes no plan_id — see
 * AdminSubscriptionController::trial().
 */
class GrantOrganizationSubscriptionTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
