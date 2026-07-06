<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\Comment;

/**
 * ENT-Comment is DEFERRED in v1 (DEF-005 / ADR-0009): the schema exists
 * because the entity remains defined in the canonical model, but no comment
 * feature ships — every ability is denied, and comment-derived surfaces
 * render "unavailable".
 */
class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Comment $comment): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Comment $comment): bool
    {
        return false;
    }

    public function delete(User $user, Comment $comment): bool
    {
        return false;
    }
}
