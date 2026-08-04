<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) ($this['id'] ?? ''),
            'post_id' => (int) ($this['post_id'] ?? 0),
            'title' => (string) ($this['title'] ?? ''),
            'status' => (string) ($this['status'] ?? 'scheduled'),
            'scheduled_at' => (string) ($this['scheduled_at'] ?? now()->toIso8601String()),
            'branch' => $this['branch'] ?? null,
        ];
    }
}
