<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_posts' => (int) ($this['total_posts'] ?? 0),
            'published' => (int) ($this['published'] ?? 0),
            'failed' => (int) ($this['failed'] ?? 0),
            'scheduled' => (int) ($this['scheduled'] ?? 0),
            'draft' => (int) ($this['draft'] ?? 0),
            'engagement' => [
                'score' => (float) ($this['engagement']['score'] ?? 0),
                'trend' => (string) ($this['engagement']['trend'] ?? 'stable'),
            ],
            'updated_at' => (string) ($this['updated_at'] ?? now()->toIso8601String()),
        ];
    }
}
