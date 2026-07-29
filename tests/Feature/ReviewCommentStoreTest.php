<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewCommentStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * レビュー返信投稿の基本動作として、認証ユーザーの返信が対象レビューへ
     * 1階層コメントとして保存され、作品詳細へ戻ることを保証する。
     */
    public function test_authenticated_user_can_store_review_comment(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => 'レビューへの返信コメントです。',
                'form_review_id' => $review->id,
            ]);

        $response
            ->assertRedirect(route('items.show', $review->item_id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '返信コメントを投稿しました。');

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => 'レビューへの返信コメントです。',
        ]);
    }

    /**
     * フォーム由来の識別子を信用しないため、返信先と投稿者がURL・認証情報から決まり、
     * parent_idが1階層コメントの仕様どおりnullになることを保証する。
     */
    public function test_request_identifiers_cannot_override_review_comment_relations(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $review = Review::factory()->create();
        $otherReview = Review::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => '保存先を確認する返信です。',
                'form_review_id' => $otherReview->id,
                'review_id' => $otherReview->id,
                'user_id' => $otherUser->id,
                'parent_id' => 999,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => '保存先を確認する返信です。',
        ]);

        $this->assertDatabaseMissing('review_comments', [
            'review_id' => $otherReview->id,
            'body' => '保存先を確認する返信です。',
        ]);
    }

    /**
     * 返信投稿は会員機能なので、未ログインユーザーはログイン画面へ戻され、
     * コメントを保存できないことを保証する。
     */
    public function test_guest_cannot_store_review_comment(): void
    {
        $review = Review::factory()->create();

        $response = $this->post(route('reviews.comments.store', $review), [
            'body' => '未ログインでの返信です。',
            'form_review_id' => $review->id,
        ]);

        $response->assertRedirect(route('login'));

        $this->assertDatabaseCount('review_comments', 0);
    }

    /**
     * 空の返信を表示・保存しないため、本文未入力が
     * reviewComment エラーバッグのバリデーションエラーになることを保証する。
     */
    public function test_review_comment_store_requires_body(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => '',
                'form_review_id' => $review->id,
            ]);

        $response->assertSessionHasErrors(['body'], null, 'reviewComment');

        $this->assertDatabaseCount('review_comments', 0);
    }

    /**
     * 仕様上の本文上限を境界値で確認し、
     * 1000文字の返信を保存できることを保証する。
     */
    public function test_review_comment_store_accepts_body_with_1000_characters(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $body = str_repeat('a', 1000);

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => $body,
                'form_review_id' => $review->id,
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $review->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }

    /**
     * 仕様上の本文上限を超える返信を保存しないため、
     * 1001文字の本文がバリデーションエラーになることを保証する。
     */
    public function test_review_comment_store_rejects_body_longer_than_1000_characters(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => str_repeat('a', 1001),
                'form_review_id' => $review->id,
            ]);

        $response->assertSessionHasErrors(['body'], null, 'reviewComment');

        $this->assertDatabaseCount('review_comments', 0);
    }

    /**
     * エラーを対象の返信フォームへ表示できるように、
     * form_review_id 未入力がバリデーションエラーになることを保証する。
     */
    public function test_review_comment_store_requires_form_review_id(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => 'フォーム識別子なしの返信です。',
            ]);

        $response->assertSessionHasErrors(['form_review_id'], null, 'reviewComment');

        $this->assertDatabaseCount('review_comments', 0);
    }

    /**
     * バリデーション失敗後も同じフォームを復元できるように、
     * 本文とフォーム識別子が old input として保持されることを保証する。
     */
    public function test_validation_failure_preserves_review_comment_old_input(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $body = str_repeat('a', 1001);

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.comments.store', $review), [
                'body' => $body,
                'form_review_id' => $review->id,
            ]);

        $response
            ->assertSessionHasErrors(['body'], null, 'reviewComment')
            ->assertSessionHasInput('body', $body)
            ->assertSessionHasInput('form_review_id', $review->id);
    }

    /**
     * 投稿後の遷移先で利用者が結果を確認できるように、
     * 保存した返信本文が対象作品詳細に表示されることを保証する。
     */
    public function test_stored_review_comment_is_displayed_on_item_detail_page(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $response = $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('reviews.comments.store', $review), [
                'body' => '作品詳細に表示される返信です。',
                'form_review_id' => $review->id,
            ]);

        $response
            ->assertOk()
            ->assertSee('作品詳細に表示される返信です。');
    }
}
