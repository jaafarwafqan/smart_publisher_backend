<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Sprint H (role/permission remediation, 2026-08-09): promoted from a plain
 * Model to a genuine Eloquent Pivot, wired via Post::socialPages()->using()
 * — this is the only way attach()/sync() writes route through this class's
 * model events instead of a raw query-builder insert, which is how
 * organization_id (added by the matching migration) gets stamped
 * automatically via BelongsToOrganization on every insert, including every
 * existing test that calls $post->socialPages()->sync(...) directly, with
 * zero call-site changes anywhere. post_targets keeps its own
 * auto-incrementing id (unlike Laravel's default composite-key pivot
 * assumption), hence $incrementing = true below.
 */
#[Fillable([
    'post_id',
    'social_page_id',
    'organization_id',
])]
class PostTarget extends Pivot
{
    use BelongsToOrganization, HasFactory;

    // Pivot::getTable() defaults to the singular class-based name
    // ("post_target") unlike a normal Eloquent Model's pluralized
    // convention — must be explicit or every query 404s against a
    // nonexistent table.
    protected $table = 'post_targets';

    public $incrementing = true;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialPage(): BelongsTo
    {
        return $this->belongsTo(SocialPage::class);
    }
}
