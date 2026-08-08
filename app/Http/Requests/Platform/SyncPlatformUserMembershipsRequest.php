<?php

namespace App\Http\Requests\Platform;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

class SyncPlatformUserMembershipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roles = implode(',', array_map(fn (OrganizationRole $role) => $role->value, OrganizationRole::cases()));

        return [
            'memberships' => ['required', 'array'],
            'memberships.*.organization_id' => ['required', 'integer', 'distinct', 'exists:organizations,id'],
            'memberships.*.role' => ['required', 'string', 'in:'.$roles],
            'memberships.*.status' => ['nullable', 'string', 'in:active,suspended'],
        ];
    }
}
