<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/organizations/{organization}/subscription — assigns a plan
 * and grants $months of paid-for period. reason is mandatory: a free grant
 * with no documented reason is an audit gap (see AdminSubscriptionController
 * ::grant(), which writes it verbatim to platform_audit_logs).
 */
class GrantOrganizationSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
