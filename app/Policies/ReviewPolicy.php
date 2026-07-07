<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * レビューを削除できるか判定する。
     *
     * @param  User  $user  ログインユーザー
     * @param  Review  $review  削除対象レビュー
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }
}
