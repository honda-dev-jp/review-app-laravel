<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $item = Item::where('title', 'サンプル映画2')->firstOrFail();

        $users = collect();

        for ($i = 1; $i <= 10; $i++) {
            $user = User::firstOrCreate(
                ['email' => 'review-user-'.$i.'@example.com'],
                [
                    'name' => 'review-user-'.$i,
                    'password' => Hash::make('password'.$i),
                ],
            );

            $users->push($user);
        }

        $reviewData = [
            [
                'rating' => 5,
                'body' => 'レビュー表示確認用のサンプルレビューです。作品の雰囲気が分かりやすく、最後まで楽しめました。',
            ],
            [
                'rating' => 4,
                'body' => 'レビュー一覧の表示確認用データです。カードの余白や本文の折り返し確認に使います。',
            ],
            [
                'rating' => 4,
                'body' => '映像の雰囲気がよく、登場人物の心情も追いやすい作品でした。レビュー一覧で本文の長さを確認するためのサンプルです。',
            ],
            [
                'rating' => 3,
                'body' => '全体的には見やすい作品でしたが、展開は少しゆっくりに感じました。中間評価の表示確認用レビューです。',
            ],
            [
                'rating' => 5,
                'body' => '最後まで集中して楽しめました。高評価レビューが複数件ある場合の平均評価表示を確認するためのデータです。',
            ],
            [
                'rating' => 2,
                'body' => '好みは分かれそうですが、レビュー本文の表示や低評価の見え方を確認するためのサンプルレビューです。',
            ],
            [
                'rating' => 4,
                'body' => 'テンポがよく、気軽に見られる作品でした。カード内で本文が自然に折り返されるかを確認します。',
            ],
            [
                'rating' => 3,
                'body' => '派手さはありませんが、落ち着いて見られる作品でした。星3評価の表示確認用レビューです。',
            ],
            [
                'rating' => 5,
                'body' => 'キャラクターの魅力が伝わりやすく、もう一度見たいと思える作品でした。高評価レビューの表示確認に使います。',
            ],
            [
                'rating' => 1,
                'body' => '今回はあまり好みに合いませんでした。低評価レビューでもレイアウトが崩れないか確認するためのデータです。',
            ],
            [
                'rating' => 4,
                'body' => '匿名ユーザー表示を確認するためのレビューです。投稿者ユーザーが存在しない場合は「匿名」と表示される想定です。',
            ],
        ];

        $normalReviewData = collect($reviewData)->take(10);

        foreach ($normalReviewData as $index => $review) {
            $user = $users[$index];

            Review::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'item_id' => $item->id,
                ],
                [
                    'rating' => $review['rating'],
                    'body' => $review['body'],
                ],
            );
        }

        $anonymousReview = $reviewData[10];

        Review::updateOrCreate(
            [
                'user_id' => null,
                'item_id' => $item->id,
            ],
            [
                'rating' => $anonymousReview['rating'],
                'body' => $anonymousReview['body'],
            ],
        );

        $ratingCount = $item->reviews()->count();
        $ratingAverage = round($item->reviews()->avg('rating'), 1);

        $item->update([
            'rating' => $ratingAverage,
            'rating_count' => $ratingCount,
        ]);
    }
}
