<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->where('user_id', $request->user()->id);
        $unread = (clone $query)->whereNull('read_at')->count();
        $items = $query->latest('id')->get();

        return response()->json([
            'unread' => $unread,
            'items' => NotificationResource::collection($items)->resolve(),
        ]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $readAt = now();

        Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => $readAt,
                'updated_at' => $readAt,
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
