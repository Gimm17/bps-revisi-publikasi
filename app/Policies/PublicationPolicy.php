<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Publication;
use App\Models\User;

class PublicationPolicy
{
    public function view(User $user, Publication $publication): bool
    {
        return $user->is_active && ($user->isAdmin() || $publication->owner_id === $user->id || $publication->assignees()->where('users.id', $user->id)->exists());
    }

    public function update(User $user, Publication $publication): bool
    {
        return $this->view($user, $publication) && ! $publication->locked_at;
    }

    public function review(User $user, Publication $publication): bool
    {
        return $this->view($user, $publication) && in_array($user->role, ['admin', 'reviewer'], true);
    }
}
