<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'provider',
    'client_id',
    'client_secret',
    'authorize_url',
    'token_url',
    'default_scopes',
    'is_enabled',
    'updated_by',
    'last_tested_at',
    'last_test_success',
])]
class OAuthProviderSetting extends Model
{
    use HasFactory;

    // Eloquent's naive snake_case conversion would derive
    // 'o_auth_provider_settings' from this class name (it splits on every
    // capital letter, not recognizing "OAuth" as one word) — pinned
    // explicitly to match the migration's actual table name.
    protected $table = 'oauth_provider_settings';

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'default_scopes' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasClientSecret(): bool
    {
        return ! empty($this->client_secret);
    }

    /**
     * No stored row means the admin never touched this provider's toggle —
     * defaults to enabled, matching SystemSettingsController's existing
     * display logic (`$setting === null ? true : $setting->is_enabled`).
     */
    public static function isEnabled(string $provider): bool
    {
        $setting = static::query()->where('provider', strtolower($provider))->first();

        return $setting === null || (bool) $setting->is_enabled;
    }
}
