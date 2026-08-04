<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotificationPolicy
{
    /**
     * A notification is private to its recipient even when both users are
     * active members of the same organization. Returning not-found prevents
     * an identifier from becoming a cross-user information oracle.
     */
    public function view(User $user, Notification $notification): Response
    {
        return $this->ownedBy($user, $notification);
    }

    public function update(User $user, Notification $notification): Response
    {
        return $this->ownedBy($user, $notification);
    }

    private function ownedBy(User $user, Notification $notification): Response
    {
        return $user->id === $notification->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
