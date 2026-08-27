<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;

/**
 * Playwright E2Eテスト用の再現可能なfixtureを生成するSeeder。
 *
 * 実在データや通常開発用Seederへ依存せず、
 * E2E専用データベース内だけに合成データを生成する。
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        // 本人レビュー一覧のページネーション確認に使う、認証済みのレビュー投稿ユーザー。
        $reviewer = User::factory()->create([
            'name' => 'E2E Reviewer',
            'email' => 'e2e-reviewer@example.test',
        ]);

        // メール未認証ユーザーに対するアクセス制御を確認するためのfixture。
        User::factory()
            ->unverified()
            ->create([
                'name' => 'E2E Unverified User',
                'email' => 'e2e-unverified@example.test',
            ]);

        // レビュー未投稿状態の画面・導線を確認するための認証済みユーザー。
        User::factory()->create([
            'name' => 'E2E No Review User',
            'email' => 'e2e-no-review@example.test',
        ]);

        // レビュー有無に依存しない一般的な認証済みユーザー操作の確認用fixture。
        User::factory()->create([
            'name' => 'E2E Verified User',
            'email' => 'e2e-verified@example.test',
        ]);

        // 作品一覧と本人レビュー一覧で2ページ目を発生させるため、同一カテゴリに11作品を用意する。
        $category = Category::factory()->create([
            'name' => 'E2E Category',
        ]);

        $items = Item::factory()
            ->count(11)
            ->state(new Sequence(
                fn (Sequence $sequence) => [
                    'title' => sprintf('E2E Movie %02d', $sequence->index + 1),
                    'description' => sprintf('E2E Movie %02d description', $sequence->index + 1),
                ],
            ))
            ->create([
                'category_id' => $category->id,
            ]);

        // 本人レビュー一覧はcreated_at DESCで並ぶため、2ページ目のfixtureを毎回同じにする目的で時刻をずらす。
        foreach ($items as $index => $item) {
            $timestamp = now()->subMinutes(11 - $index);

            Review::factory()->create([
                'user_id' => $reviewer->id,
                'item_id' => $item->id,
                'rating' => 5,
                'body' => sprintf('E2E Review %02d', $index + 1),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }
}
