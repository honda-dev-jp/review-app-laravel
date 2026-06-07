<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ReviewComment;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReviewCommentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $item = Item::where('title', 'サンプル映画2')->firstOrFail();

        $reviews = $item->reviews()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(3)
            ->get();

        $user = User::where('email', 'review-user-1@example.com')->firstOrFail();

        $commentData = [
            '返信コメント表示確認用のサンプル返信です。',
            'レビューに紐づく返信コメントが表示されるか確認するためのデータです。',
            '匿名返信コメント表示を確認するためのサンプル返信です。',
        ];

        foreach ($reviews as $index => $review) {
            $commentUserId = $index === 2 ? null : $user->id;

            ReviewComment::updateOrCreate(
                [
                    'review_id' => $review->id,
                    'user_id' => $commentUserId,
                    'parent_id' => null,
                ],
                [
                    'body' => $commentData[$index],
                ],
            );
        }
    }
}
