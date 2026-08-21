<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'organization_id', 'user_id', 'post_id', 'operation', 'provider',
    'status', 'duration_ms', 'input_characters', 'output_characters',
    'correlation_id', 'request_id',
])]
class AiUsageLog extends Model
{
    use BelongsToOrganization;

    public $timestamps = true;
}
