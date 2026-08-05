<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewMineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 本人レビュー一覧はPR8の主機能なので、
     * ログインユーザーが自分のレビュー履歴を閲覧できることを保証する。
     */
    public function test_authenticated_user_can_view_my_reviews_page(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create([
            'title' => 'サンプル映画テスト',
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => '本人レビュー本文です。',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSee('投稿したレビュー')
            ->assertSee('レビュー履歴')
            ->assertSee('サンプル映画テスト')
            ->assertSee('本人レビュー本文です。')
            ->assertSee('レビューを削除する');
    }

    /**
     * 本人レビュー一覧固有の表示とPC・モバイル用ナビゲーションは別要素であるため、
     * いずれか一つだけアバター表示が欠落する回帰を防ぐ。
     */
    public function test_my_reviews_page_and_both_navigation_variants_display_avatar(): void
    {
        Storage::fake('public');

        $avatarPath = 'avatars/my-reviews-avatar.jpg';
        Storage::disk('public')->put($avatarPath, 'avatar image');

        $user = User::factory()->create(['avatar_path' => $avatarPath]);
        $avatarUrl = Storage::disk('public')->url($avatarPath);

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response->assertOk();
        $this->assertSame(3, substr_count(
            $response->getContent(),
            'src="'.$avatarUrl.'"'
        ));
    }

    /**
     * 本人レビュー一覧はログイン済み会員向け画面なので、
     * 未ログインユーザーが閲覧できないことを保証する。
     */
    public function test_guest_cannot_view_my_reviews_page(): void
    {
        $response = $this->get(route('reviews.mine'));

        $response->assertRedirect(route('login'));
    }

    /**
     * 本人レビュー一覧は共通ナビゲーションから利用する画面なので、
     * ログインユーザー向けナビに正しいリンクが表示されることを保証する。
     */
    public function test_authenticated_user_navigation_shows_my_reviews_link(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response
            ->assertOk()
            ->assertSee('href="'.route('reviews.mine').'"', false)
            ->assertSee('本人レビュー一覧');
    }

    /**
     * 本人レビュー一覧では自分のレビューだけを表示する仕様なので、
     * 他ユーザーのレビューが混ざらないことを保証する。
     */
    public function test_my_reviews_page_shows_only_authenticated_users_reviews(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownItem = Item::factory()->create([
            'title' => '自分のレビュー対象作品',
        ]);

        $otherItem = Item::factory()->create([
            'title' => '他人のレビュー対象作品',
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $ownItem->id,
            'rating' => 5,
            'body' => '自分のレビュー本文です。',
        ]);

        Review::factory()->create([
            'user_id' => $otherUser->id,
            'item_id' => $otherItem->id,
            'rating' => 3,
            'body' => '他人のレビュー本文です。',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSee('自分のレビュー対象作品')
            ->assertSee('自分のレビュー本文です。')
            ->assertDontSee('他人のレビュー対象作品')
            ->assertDontSee('他人のレビュー本文です。');
    }

    /**
     * 本人レビュー一覧の件数から他ユーザーの投稿状況が混ざらないように、
     * 表示件数がログインユーザー本人のレビュー数だけになることを保証する。
     */
    public function test_my_reviews_page_count_includes_only_authenticated_users_reviews(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        for ($number = 1; $number <= 2; $number++) {
            $item = Item::factory()->create([
                'title' => sprintf('件数集計本人作品%02d', $number),
            ]);

            Review::factory()->create([
                'user_id' => $user->id,
                'item_id' => $item->id,
                'body' => sprintf('件数集計本人レビュー%02d', $number),
            ]);
        }

        for ($number = 1; $number <= 3; $number++) {
            $item = Item::factory()->create([
                'title' => sprintf('件数集計他人作品%02d', $number),
            ]);

            Review::factory()->create([
                'user_id' => $otherUser->id,
                'item_id' => $item->id,
                'body' => sprintf('件数集計他人レビュー%02d', $number),
            ]);
        }

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSee('2件')
            ->assertDontSee('5件')
            ->assertDontSee('件数集計他人作品')
            ->assertDontSee('件数集計他人レビュー');
    }

    /**
     * レビュー未投稿のユーザーでも画面が破綻しないように、
     * 空状態のメッセージが表示されることを保証する。
     */
    public function test_my_reviews_page_shows_empty_message_when_user_has_no_reviews(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSee('まだレビューを投稿していません。')
            ->assertSee('作品詳細画面からレビューを投稿すると、ここに表示されます。')
            ->assertSee('作品一覧を見る');
    }

    /**
     * 本人レビュー一覧から削除した場合は画面遷移を維持したいので、
     * 削除後に本人レビュー一覧へ戻ることを保証する。
     */
    public function test_delete_review_from_my_reviews_page_redirects_to_my_reviews(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $review = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 4,
            'body' => '削除対象の本人レビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('reviews.destroy', $review), [
                'redirect_to' => 'reviews.mine',
            ]);

        $response
            ->assertRedirect(route('reviews.mine'))
            ->assertSessionHas('status', 'レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', [
            'id' => $review->id,
        ]);
    }

    /**
     * 本人レビュー一覧は10件ずつ表示する仕様なので、
     * 11件目のレビューが2ページ目に分かれることを保証する。
     */
    public function test_my_reviews_page_paginates_reviews(): void
    {
        $user = User::factory()->create();

        for ($number = 1; $number <= 11; $number++) {
            $item = Item::factory()->create([
                'title' => sprintf('ページネーション確認作品%02d', $number),
            ]);

            Review::factory()->create([
                'user_id' => $user->id,
                'item_id' => $item->id,
                'rating' => 5,
                'body' => sprintf('ページネーション確認レビュー%02d', $number),
                'created_at' => now()->subMinutes($number),
            ]);
        }

        $firstPageResponse = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $firstPageResponse
            ->assertOk()
            ->assertSee('11件')
            ->assertSee('ページネーション確認レビュー01')
            ->assertSee('ページネーション確認レビュー10')
            ->assertDontSee('ページネーション確認レビュー11')
            ->assertSee('page=2', false);

        $secondPageResponse = $this
            ->actingAs($user)
            ->get(route('reviews.mine', ['page' => 2]));

        $secondPageResponse
            ->assertOk()
            ->assertSee('ページネーション確認レビュー11')
            ->assertDontSee('ページネーション確認レビュー01');
    }

    /**
     * 本人レビュー一覧はレビュー履歴を新着順に確認する画面なので、
     * 投稿日時の新しいレビューから順に表示されることを保証する。
     */
    public function test_my_reviews_page_shows_reviews_in_newest_first_order(): void
    {
        $user = User::factory()->create();
        $baseTime = now();

        $oldItem = Item::factory()->create();
        $middleItem = Item::factory()->create();
        $newItem = Item::factory()->create();

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $oldItem->id,
            'body' => '表示順確認・古いレビュー',
            'created_at' => $baseTime->copy()->subDays(2),
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $middleItem->id,
            'body' => '表示順確認・中間レビュー',
            'created_at' => $baseTime->copy()->subDay(),
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $newItem->id,
            'body' => '表示順確認・新しいレビュー',
            'created_at' => $baseTime,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSeeInOrder([
                '表示順確認・新しいレビュー',
                '表示順確認・中間レビュー',
                '表示順確認・古いレビュー',
            ]);
    }

    /**
     * レビュー削除導線は本人レビュー一覧画面に限定する仕様なので、
     * 作品詳細画面に削除ボタンや削除フォームが表示されないことを保証する。
     */
    public function test_item_show_page_does_not_show_review_delete_action(): void
    {
        $user = User::factory()->create();

        $item = Item::factory()->create([
            'title' => '削除導線確認作品',
        ]);

        $review = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => '作品詳細画面に表示されるレビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('items.show', $item));

        $response
            ->assertOk()
            ->assertSee('削除導線確認作品')
            ->assertSee('作品詳細画面に表示されるレビューです。')
            ->assertDontSee('レビューを削除する')
            ->assertDontSee('action="'.route('reviews.destroy', $review).'"', false);
    }

    /**
     * 本人レビュー一覧から削除後に同じ画面へ戻るにはBlade側のフォーム値が重要なので、
     * 削除フォームが正しい削除先・DELETEメソッド・戻り先を持つことを保証する。
     */
    public function test_my_reviews_page_renders_delete_form_with_redirect_to_my_reviews(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $review = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 4,
            'body' => '削除フォーム確認用レビューです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('reviews.mine'));

        $response
            ->assertOk()
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.route('reviews.destroy', $review).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('name="redirect_to" value="reviews.mine"', false);
    }
}
