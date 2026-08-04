<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'provider',
    'user_id',
    'action',
    'changed_fields',
    'success',
])]
class OAuthProviderSettingAuditLog extends Model
{
    // Same Eloquent snake_case gotcha as OAuthProviderSetting — "OAuth" isn't
    // recognized as one word, so this must be pinned to the real table name.
    protected $table = 'oauth_provider_setting_audit_logs';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'success' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
