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

        DB::table('items')->updateOrInsert(
            ['title' => 'サンプル映画1'],
            [
                'category_id' => $categoryId,
                'description' => '作品一覧・作品詳細画面の表示確認用データです。',
                'image_path' => null,
                'rating' => null,
                'rating_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('items')->updateOrInsert(
            ['title' => 'サンプル映画2'],
            [
                'category_id' => $categoryId,
                'description' => 'レビュー表示確認用のサンプル作品です。',
                'image_path' => null,
                'rating' => null,
                'rating_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
