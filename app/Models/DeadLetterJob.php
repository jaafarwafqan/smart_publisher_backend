<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'queue_name',
    'job_class',
    'reference_type',
    'reference_id',
    'payload',
    'error_message',
    'attempts',
    'failed_at',
    'retried_at',
    'retried_by',
])]
class DeadLetterJob extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }

    public function retriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }
}
