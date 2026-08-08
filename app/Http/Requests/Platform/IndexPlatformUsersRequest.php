<?php

namespace App\Http\Requests\Platform;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

class IndexPlatformUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_super_admin' => ['nullable', 'boolean'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'membership_role' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (OrganizationRole $role) => $role->value, OrganizationRole::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
