<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use App\Services\ItemRatingService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Factoryでレビューを作成した場合も、
     * 画面表示用の評価キャッシュと不整合にならないようにする。
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Review $review): void {
            app(ItemRatingService::class)->refresh($review->item);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'item_id' => Item::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->realText(120),
        ];
    }
}
