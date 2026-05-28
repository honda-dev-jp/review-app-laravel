<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('categories')->updateOrInsert(
            ['name' => '未定義'],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
