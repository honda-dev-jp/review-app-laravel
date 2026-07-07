<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * レビュー投稿はPR7の主機能であり、
     * ログインユーザーが評価と本文を保存できることを保証する。
     */
    public function test_authenticated_user_can_store_review(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 5,
                'body' => 'とても面白い作品でした。',
            ]);

        $response
            ->assertRedirect(route('items.show', $item))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'レビューを投稿しました。');

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => 'とても面白い作品でした。',
        ]);
    }

    /**
     * 作品一覧・詳細では items.rating / items.rating_count を表示に使うため、
     * レビュー投稿時に評価キャッシュが更新されることを保証する。
     */
    public function test_item_rating_cache_is_updated_when_review_is_stored(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 4,
                'body' => '評価キャッシュ確認用のレビューです。',
            ]);

        $item->refresh();

        $this->assertSame(4.0, (float) $item->rating);
        $this->assertSame(1, $item->rating_count);
    }

    /**
     * レビュー投稿は会員機能なので、
     * 未ログインユーザーが投稿できないことを保証する。
     */
    public function test_guest_cannot_store_review(): void
    {
        $item = Item::factory()->create();

        $response = $this->post(route('reviews.store', $item), [
            'rating' => 5,
            'body' => '未ログイン投稿です。',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * 不正な評価値や空の本文を保存しないため、
     * StoreReviewRequest のバリデーションが効くことを保証する。
     */
    public function test_review_store_requires_valid_rating_and_body(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => '',
                'body' => '',
            ]);

        $response->assertSessionHasErrors(['rating', 'body']);

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * rating は平均評価キャッシュの計算元になるため、
     * 1〜5の整数以外を保存できないことを Form Request 経由で保証する。
     */
    public function test_review_store_rejects_invalid_rating_values(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        foreach ([0, 6, 3.5] as $rating) {
            $response = $this
                ->actingAs($user)
                ->post(route('reviews.store', $item), [
                    'rating' => $rating,
                    'body' => '不正な評価値のレビューです。',
                ]);

            $response->assertSessionHasErrors(['rating']);
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * レビュー本文は画面表示とDB保存の対象になるため、
     * PR7で定めた最大1000文字を超える本文を保存しないことを保証する。
     */
    public function test_review_store_rejects_body_longer_than_1000_characters(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 5,
                'body' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors(['body']);

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * 1ユーザー1作品1レビューの仕様を守るため、
     * 同じ作品への重複レビュー投稿を防げることを保証する。
     */
    public function test_authenticated_user_cannot_store_duplicate_review_for_same_item(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => 'すでに投稿済みのレビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 4,
                'body' => '重複投稿しようとしたレビューです。',
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHasErrors(['body']);

        $this->assertDatabaseCount('reviews', 1);
    }

    /**
     * レビュー削除はPR7の対象機能であり、
     * 自分のレビューを削除した後に評価キャッシュが再計算されることを保証する。
     */
    public function test_authenticated_user_can_delete_own_review_and_rating_cache_is_recalculated(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();

        $ownReview = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => '削除対象のレビューです。',
        ]);

        Review::factory()->create([
            'user_id' => $otherUser->id,
            'item_id' => $item->id,
            'rating' => 3,
            'body' => '残るレビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('reviews.destroy', $ownReview));

        $response
            ->assertRedirect(route('items.show', $item))
            ->assertSessionHas('status', 'レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', [
            'id' => $ownReview->id,
        ]);

        $item->refresh();

        $this->assertSame(3.0, (float) $item->rating);
        $this->assertSame(1, $item->rating_count);
    }

    /**
     * 最後のレビュー削除時は平均評価を表示できなくなるため、
     * rating を null、rating_count を 0 に戻せることを保証する。
     */
    public function test_item_rating_cache_is_cleared_when_last_review_is_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $review = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => '最後に削除されるレビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('reviews.destroy', $review));

        $response
            ->assertRedirect(route('items.show', $item))
            ->assertSessionHas('status', 'レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', [
            'id' => $review->id,
        ]);

        $item->refresh();

        $this->assertNull($item->rating);
        $this->assertSame(0, $item->rating_count);
    }

    /**
     * レビュー削除は本人のみ許可する仕様なので、
     * 他人のレビューを削除できないことを Policy 経由で保証する。
     */
    public function test_authenticated_user_cannot_delete_other_users_review(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();

        $review = Review::factory()->create([
            'user_id' => $otherUser->id,
            'item_id' => $item->id,
            'rating' => 4,
            'body' => '他人のレビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('reviews.destroy', $review));

        $response->assertForbidden();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $otherUser->id,
            'item_id' => $item->id,
        ]);
    }

    /**
     * レビュー削除はログイン済み会員だけの操作なので、
     * 未ログインユーザーが削除できずレビュー本文と評価が残ることを保証する。
     */
    public function test_guest_cannot_delete_review(): void
    {
        $review = Review::factory()->create();

        $response = $this->delete(route('reviews.destroy', $review));

        $response->assertRedirect(route('login'));

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $review->user_id,
            'item_id' => $review->item_id,
        ]);
    }
}
