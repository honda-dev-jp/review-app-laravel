<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categoryId = DB::table('categories')
            ->where('name', '未定義')
            ->value('id');

        $now = now();

        $items = [
            [
                'title' => 'サンプル映画1',
                'description' => '作品一覧・作品詳細画面の表示確認用データです。',
            ],
            [
                'title' => 'サンプル映画2',
                'description' => 'レビュー表示確認用のサンプル作品です。',
            ],
            [
                'title' => 'サンプル映画3',
                'description' => 'ページネーション確認用のサンプル作品です。',
            ],
            [
                'title' => 'サンプル映画4',
                'description' => '作品カード表示確認用のサンプル作品です。',
            ],
            [
                'title' => 'サンプル映画5',
                'description' => '作品一覧の複数件表示を確認するためのサンプル作品です。',
            ],
            [
                'title' => 'サンプル映画6',
                'description' => '作品詳細画面への遷移確認用データです。',
            ],
            [
                'title' => 'サンプル映画7',
                'description' => '平均評価と評価件数の表示確認用データです。',
            ],
            [
                'title' => 'サンプル映画8',
                'description' => 'カテゴリ表示枠の確認用サンプル作品です。',
            ],
            [
                'title' => 'サンプル映画9',
                'description' => 'レスポンシブ表示確認用のサンプル作品です。',
            ],
            [
                'title' => 'サンプル映画10',
                'description' => '作品一覧1ページ目の最終表示確認用データです。',
            ],
            [
                'title' => 'サンプル映画11',
                'description' => 'ページネーション2ページ目の表示確認用データです。',
            ],
        ];

        foreach ($items as $item) {
            DB::table('items')->updateOrInsert(
                ['title' => $item['title']],
                [
                    'category_id' => $categoryId,
                    'description' => $item['description'],
                    'image_path' => null,
                    'rating' => null,
                    'rating_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
