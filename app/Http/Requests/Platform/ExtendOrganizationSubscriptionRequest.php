<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/organizations/{organization}/subscription/extend — extends
 * the CURRENT plan's period by either days or months (at least one is
 * required; both may be supplied together). reason is mandatory, same as
 * every other subscription-mutating admin action — see
 * AdminSubscriptionController::extend().
 */
class ExtendOrganizationSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['required_without:months', 'nullable', 'integer', 'min:1', 'max:1825'],
            'months' => ['required_without:days', 'nullable', 'integer', 'min:1', 'max:60'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
