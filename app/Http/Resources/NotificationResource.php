<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->notification();
        $isRead = $notification->read_at !== null;

        return [
            'id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'body' => (string) $notification->body,
            // Keep both spellings while clients transition. Flutter accepts
            // either field and the prior facade exposed `read`.
            'is_read' => $isRead,
            'read' => $isRead,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function notification(): Notification
    {
        if (! $this->resource instanceof Notification) {
            throw new LogicException('NotificationResource requires a Notification model.');
        }

        return $this->resource;
    }
}
