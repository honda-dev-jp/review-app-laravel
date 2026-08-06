<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
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
     * 返信本文エラーと入力欄の関係を支援技術へ伝え、
     * 複数の返信フォームでは送信元だけがエラー状態になることを保証する。
     */
    public function test_review_comment_body_validation_error_has_accessible_aria_attributes_only_on_source_form(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $otherReview = Review::factory()->create([
            'item_id' => $review->item_id,
        ]);

        // エラーがない通常表示では、入力欄にエラー用ARIA属性が付かないことを確認する。
        $normalResponse = $this
            ->actingAs($user)
            ->get(route('items.show', $review->item_id));

        $normalResponse->assertOk();

        $normalHtml = $normalResponse->getContent();
        $this->assertIsString($normalHtml);

        $normalXPath = $this->createXPath($normalHtml);
        $normalSourceTextarea = $this->getSingleElementById($normalXPath, 'comment-body-'.$review->id);
        $normalOtherTextarea = $this->getSingleElementById($normalXPath, 'comment-body-'.$otherReview->id);
        $normalSourceLabels = $normalXPath->query('//label[@for="comment-body-'.$review->id.'"]');
        $normalOtherLabels = $normalXPath->query('//label[@for="comment-body-'.$otherReview->id.'"]');
        $normalSourceErrors = $normalXPath->query('//*[@id="comment-body-error-'.$review->id.'"]');
        $normalOtherErrors = $normalXPath->query('//*[@id="comment-body-error-'.$otherReview->id.'"]');

        $this->assertSame('textarea', $normalSourceTextarea->tagName);
        $this->assertFalse($normalSourceTextarea->hasAttribute('aria-invalid'));
        $this->assertFalse($normalSourceTextarea->hasAttribute('aria-describedby'));
        $this->assertNotFalse($normalSourceLabels);
        $this->assertCount(1, $normalSourceLabels);
        $this->assertNotFalse($normalSourceErrors);
        $this->assertCount(0, $normalSourceErrors);
        $this->assertSame('textarea', $normalOtherTextarea->tagName);
        $this->assertFalse($normalOtherTextarea->hasAttribute('aria-invalid'));
        $this->assertFalse($normalOtherTextarea->hasAttribute('aria-describedby'));
        $this->assertNotFalse($normalOtherLabels);
        $this->assertCount(1, $normalOtherLabels);
        $this->assertNotFalse($normalOtherErrors);
        $this->assertCount(0, $normalOtherErrors);

        $response = $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('reviews.comments.store', $review), [
                'body' => '',
                'form_review_id' => $review->id,
            ]);

        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);

        // 文字列の出現順だけでは対象textareaへの付与を保証できないため、DOM要素の属性を直接確認する。
        $xpath = $this->createXPath($html);
        $sourceTextarea = $this->getSingleElementById($xpath, 'comment-body-'.$review->id);
        $otherTextarea = $this->getSingleElementById($xpath, 'comment-body-'.$otherReview->id);
        $errorElement = $this->getSingleElementById($xpath, 'comment-body-error-'.$review->id);

        $this->assertSame('textarea', $sourceTextarea->tagName);
        $this->assertSame('true', $sourceTextarea->getAttribute('aria-invalid'));
        $this->assertSame(
            'comment-body-error-'.$review->id,
            $sourceTextarea->getAttribute('aria-describedby')
        );
        $this->assertSame('返信本文を入力してください。', trim($errorElement->textContent));

        $this->assertSame('textarea', $otherTextarea->tagName);
        $this->assertFalse($otherTextarea->hasAttribute('aria-invalid'));
        $this->assertFalse($otherTextarea->hasAttribute('aria-describedby'));

        $otherErrorElements = $xpath->query('//*[@id="comment-body-error-'.$otherReview->id.'"]');
        $this->assertNotFalse($otherErrorElements);
        $this->assertCount(0, $otherErrorElements);

        $invalidElements = $xpath->query('//*[@aria-invalid="true"]');
        $this->assertNotFalse($invalidElements);
        $this->assertCount(1, $invalidElements);
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

    private function createXPath(string $html): DOMXPath
    {
        // HTML5解析時の警告をテスト出力へ出さないよう、libxmlのエラー処理を一時的に内部化する。
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));

            return new DOMXPath($document);
        } finally {
            // 後続テストへ影響させないよう、解析エラーを消去して元の設定へ戻す。
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function getSingleElementById(DOMXPath $xpath, string $id): DOMElement
    {
        $elements = $xpath->query(sprintf('//*[@id="%s"]', $id));

        $this->assertNotFalse($elements);
        $this->assertCount(1, $elements);

        $element = $elements->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }
}
