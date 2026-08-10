<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single audit trail for both platform-wide super_admin actions
 * (organization_id null — e.g. a user's platform role changing) and
 * organization-scoped actions (Sprint G, role/permission remediation:
 * social account connect/update/test/sync/disconnect/delete, member role
 * changes, post approve/reject). Never stores tokens/secrets — old_values/
 * new_values are always caller-supplied, safe field-name-or-scalar payloads,
 * never raw request bodies (see SystemSettingsController's own precedent of
 * recording only changed_fields, not values, for secrets specifically).
 */
#[Fillable([
    'actor_user_id',
    'organization_id',
    'action',
    'auditable_type',
    'auditable_id',
    'old_values',
    'new_values',
    'correlation_id',
    'request_id',
    'ip_address',
])]
class PlatformAuditLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
