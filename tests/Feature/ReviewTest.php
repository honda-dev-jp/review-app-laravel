<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\User;
use App\Services\ItemRatingService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\Expectation;
use Mockery\MockInterface;
use RuntimeException;
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

        $pageResponse = $this->actingAs($user)->get(route('items.show', $item));
        $pageResponse->assertOk();

        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame('レビューを投稿しました。', trim($statusElement->textContent));
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
     * 評価の下限値と本文の上限値が同時に指定されても保存できることを保証する。
     */
    public function test_review_store_accepts_rating_one_and_body_with_1000_characters(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $body = str_repeat('a', 1000);

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 1,
                'body' => $body,
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 1,
            'body' => $body,
        ]);
    }

    /**
     * リクエスト中の識別子ではなく、認証ユーザーとURL上の作品でレビューが保存されることを保証する。
     */
    public function test_request_identifiers_cannot_override_review_relations(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();
        $otherItem = Item::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'user_id' => $otherUser->id,
                'item_id' => $otherItem->id,
                'rating' => 4,
                'body' => '保存先を確認するレビューです。',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'item_id' => $item->id,
            'body' => '保存先を確認するレビューです。',
        ]);
        $this->assertDatabaseMissing('reviews', [
            'user_id' => $otherUser->id,
            'body' => '保存先を確認するレビューです。',
        ]);
        $this->assertDatabaseMissing('reviews', [
            'item_id' => $otherItem->id,
            'body' => '保存先を確認するレビューです。',
        ]);
    }

    /**
     * 複数レビュー投稿後の平均評価が小数1桁で更新されることを保証する。
     */
    public function test_item_rating_cache_calculates_decimal_average_after_multiple_reviews(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $item = Item::factory()->create();

        $this
            ->actingAs($firstUser)
            ->post(route('reviews.store', $item), [
                'rating' => 4,
                'body' => '平均評価確認用レビュー1です。',
            ])
            ->assertSessionHasNoErrors();

        $this
            ->actingAs($secondUser)
            ->post(route('reviews.store', $item), [
                'rating' => 5,
                'body' => '平均評価確認用レビュー2です。',
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertSame(4.5, (float) $item->rating);
        $this->assertSame(2, $item->rating_count);
    }

    /**
     * PHP 8.4環境でも、平均値が丸め境界となる場合に
     * 小数第1位へ期待どおり丸められることを保証する。
     */
    public function test_item_rating_cache_rounds_half_boundary_to_one_decimal_place(): void
    {
        $item = Item::factory()->create();
        $users = User::factory()->count(20)->create();
        $now = now();

        // ReviewFactoryのafterCreatingによる自動集計を避け、
        // 集計対象を準備してからItemRatingServiceを1回だけ実行する。
        Review::query()->insert(
            $users
                ->map(fn (User $user, int $index): array => [
                    'user_id' => $user->id,
                    'item_id' => $item->id,
                    'rating' => $index < 17 ? 3 : 2,
                    'body' => 'PHP 8.4の丸め境界確認用レビューです。',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all()
        );

        app(ItemRatingService::class)->refresh($item);
        $item->refresh();

        // (3 × 17 + 2 × 3) ÷ 20 = 2.85
        $this->assertSame(2.9, (float) $item->rating);
        $this->assertSame(20, $item->rating_count);
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
     * 作品詳細のレビュー投稿者と返信投稿者へ、それぞれのアバターが表示されることを確認する。
     */
    public function test_item_show_displays_review_and_comment_author_avatars(): void
    {
        Storage::fake('public');

        $reviewerAvatarPath = 'avatars/reviewer-avatar.jpg';
        $commenterAvatarPath = 'avatars/commenter-avatar.jpg';
        Storage::disk('public')->put($reviewerAvatarPath, 'reviewer avatar');
        Storage::disk('public')->put($commenterAvatarPath, 'commenter avatar');

        $reviewer = User::factory()->create(['avatar_path' => $reviewerAvatarPath]);
        $commenter = User::factory()->create(['avatar_path' => $commenterAvatarPath]);
        $review = Review::factory()->for($reviewer)->create([
            'body' => 'アバター表示確認用レビューです。',
        ]);
        ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => $commenter->id,
            'parent_id' => null,
            'body' => 'アバター表示確認用返信です。',
        ]);

        $response = $this->get(route('items.show', $review->item_id));

        $response
            ->assertOk()
            ->assertSeeText($reviewer->name)
            ->assertSeeText($commenter->name);

        $xpath = $this->createXPath($response->getContent());
        $reviewerAvatars = $xpath->query(sprintf(
            '//img[@src="%s"]',
            Storage::disk('public')->url($reviewerAvatarPath)
        ));
        $commenterAvatars = $xpath->query(sprintf(
            '//img[@src="%s"]',
            Storage::disk('public')->url($commenterAvatarPath)
        ));

        $this->assertNotFalse($reviewerAvatars);
        $this->assertCount(1, $reviewerAvatars);
        $this->assertNotFalse($commenterAvatars);
        $this->assertCount(1, $commenterAvatars);
    }

    /**
     * メール未認証ユーザーからの公開レビュー投稿を防ぐため、
     * 認証案内画面へリダイレクトされ、レビューが保存されないことを保証する。
     */
    public function test_unverified_user_cannot_store_review(): void
    {
        $user = User::factory()->unverified()->create();
        $item = Item::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('reviews.store', $item), [
                'rating' => 5,
                'body' => '未認証ユーザーのレビューです。',
            ]);

        $response->assertRedirect(route('verification.notice'));

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
     * レビュー本文エラーと入力欄の関係を支援技術へ伝え、
     * 正常表示時には誤ったエラー状態を示さないことを保証する。
     */
    public function test_review_body_validation_error_has_accessible_aria_attributes(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        // エラーがない通常表示では、入力欄にエラー用ARIA属性が付かないことを確認する。
        $normalResponse = $this
            ->actingAs($user)
            ->get(route('items.show', $item));

        $normalResponse->assertOk();

        $normalHtml = $normalResponse->getContent();
        $this->assertIsString($normalHtml);

        $normalXPath = $this->createXPath($normalHtml);
        $normalTextarea = $this->getSingleElementById($normalXPath, 'body');
        $normalLabels = $normalXPath->query('//label[@for="body"]');

        $this->assertSame('textarea', $normalTextarea->tagName);
        $this->assertFalse($normalTextarea->hasAttribute('aria-invalid'));
        $this->assertFalse($normalTextarea->hasAttribute('aria-describedby'));
        $this->assertNotFalse($normalLabels);
        $this->assertCount(1, $normalLabels);

        $normalErrorElements = $normalXPath->query('//*[@id="review-body-error"]');
        $this->assertNotFalse($normalErrorElements);
        $this->assertCount(0, $normalErrorElements);

        $normalStatusElements = $normalXPath->query('//*[@role="status"]');
        $this->assertNotFalse($normalStatusElements);
        $this->assertCount(0, $normalStatusElements);

        $response = $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('reviews.store', $item), [
                'rating' => 5,
                'body' => '',
            ]);

        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);

        // 文字列の出現順だけでは対象textareaへの付与を保証できないため、DOM要素の属性を直接確認する。
        $xpath = $this->createXPath($html);
        $textarea = $this->getSingleElementById($xpath, 'body');
        $errorElement = $this->getSingleElementById($xpath, 'review-body-error');

        $this->assertSame('textarea', $textarea->tagName);
        $this->assertSame('true', $textarea->getAttribute('aria-invalid'));
        $this->assertSame('review-body-error', $textarea->getAttribute('aria-describedby'));
        $this->assertSame('レビュー本文を入力してください。', trim($errorElement->textContent));
    }

    /**
     * 評価エラーをselect自身へ関連付け、レビュー本文と返信フォームへエラー状態を漏らさないことを確認する。
     */
    public function test_review_rating_validation_error_has_accessible_aria_attributes(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();
        $otherReview = Review::factory()->create([
            'user_id' => $otherUser->id,
            'item_id' => $item->id,
        ]);

        $normalResponse = $this
            ->actingAs($user)
            ->get(route('items.show', $item));

        $normalResponse->assertOk();

        $normalXPath = $this->createXPath($normalResponse->getContent());
        $normalRating = $this->getSingleElementById($normalXPath, 'rating');
        $normalBody = $this->getSingleElementById($normalXPath, 'body');
        $normalCommentBody = $this->getSingleElementById($normalXPath, 'comment-body-'.$otherReview->id);
        $ratingLabels = $normalXPath->query('//label[@for="rating"]');

        foreach ([$normalRating, $normalBody, $normalCommentBody] as $element) {
            $this->assertFalse($element->hasAttribute('aria-invalid'));
            $this->assertFalse($element->hasAttribute('aria-describedby'));
        }

        $this->assertNotFalse($ratingLabels);
        $this->assertCount(1, $ratingLabels);

        $normalRatingErrors = $normalXPath->query('//*[@id="review-rating-error"]');
        $this->assertNotFalse($normalRatingErrors);
        $this->assertCount(0, $normalRatingErrors);

        $response = $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('reviews.store', $item), [
                'rating' => '',
                'body' => '評価エラー時のフォーム分離を確認するレビューです。',
            ]);

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $rating = $this->getSingleElementById($xpath, 'rating');
        $error = $this->getSingleElementById($xpath, 'review-rating-error');
        $body = $this->getSingleElementById($xpath, 'body');
        $commentBody = $this->getSingleElementById($xpath, 'comment-body-'.$otherReview->id);

        $this->assertSame('select', $rating->tagName);
        $this->assertSame('true', $rating->getAttribute('aria-invalid'));
        $this->assertSame('review-rating-error', $rating->getAttribute('aria-describedby'));
        $this->assertSame('評価を選択してください。', trim($error->textContent));

        foreach ([$body, $commentBody] as $element) {
            $this->assertFalse($element->hasAttribute('aria-invalid'));
            $this->assertFalse($element->hasAttribute('aria-describedby'));
        }

        $invalidElements = $xpath->query('//*[@aria-invalid="true"]');
        $reviewBodyErrors = $xpath->query('//*[@id="review-body-error"]');

        $this->assertNotFalse($invalidElements);
        $this->assertCount(1, $invalidElements);
        $this->assertNotFalse($reviewBodyErrors);
        $this->assertCount(0, $reviewBodyErrors);
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

        // 削除元により戻り先が変わるため、作品詳細へ戻る経路の通知DOMをここで保証する。
        $pageResponse = $this->actingAs($user)->get(route('items.show', $item));
        $pageResponse->assertOk();

        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame('レビューを削除しました。', trim($statusElement->textContent));
    }

    /**
     * Issue #93でレビュー削除はメール未認証でも使える既存管理機能として維持するため、
     * verified ミドルウェアが過剰適用されても検出できるように自分のレビュー削除を保証する。
     */
    public function test_unverified_user_can_delete_own_review_and_rating_cache_is_recalculated(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();

        $ownReview = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 5,
            'body' => '未認証ユーザーが削除するレビューです。',
        ]);

        Review::factory()->create([
            'user_id' => $otherUser->id,
            'item_id' => $item->id,
            'rating' => 3,
            'body' => '未認証ユーザーの削除後に残るレビューです。',
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
     * レビュー削除後の評価更新に失敗した場合、削除と評価キャッシュがロールバックされることを保証する。
     */
    public function test_review_deletion_is_rolled_back_when_rating_cache_refresh_fails(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $review = Review::factory()->create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'rating' => 4,
            'body' => 'ロールバック確認用レビューです。',
        ]);
        $item->refresh();
        $ratingBeforeDeletion = $item->rating;
        $ratingCountBeforeDeletion = $item->rating_count;

        // FactoryのafterCreatingによる評価更新が完了してから、削除処理だけを失敗させる。
        $this->mock(ItemRatingService::class, function (MockInterface $mock): void {
            $refreshExpectation = $mock->shouldReceive('refresh');

            assert($refreshExpectation instanceof Expectation);

            $refreshExpectation->andThrow(
                new RuntimeException('評価キャッシュ更新失敗')
            );
        });

        $this->withoutExceptionHandling();

        try {
            $this
                ->actingAs($user)
                ->delete(route('reviews.destroy', $review));

            $this->fail('評価キャッシュ更新例外が送出されませんでした。');
        } catch (RuntimeException $exception) {
            $this->assertSame('評価キャッシュ更新失敗', $exception->getMessage());
        }

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $user->id,
            'item_id' => $item->id,
        ]);

        $item->refresh();

        $this->assertSame($ratingBeforeDeletion, $item->rating);
        $this->assertSame($ratingCountBeforeDeletion, $item->rating_count);
    }

    /**
     * 認可された削除ルートでレビューを削除すると、紐づく返信もcascade削除されることを保証する。
     */
    public function test_deleting_review_cascade_deletes_its_comments(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create();
        $comment = ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => User::factory()->create()->id,
            'parent_id' => null,
            'body' => 'レビューとともに削除される返信です。',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('reviews.destroy', $review))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('reviews', [
            'id' => $review->id,
        ]);
        $this->assertDatabaseMissing('review_comments', [
            'id' => $comment->id,
        ]);
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

    /**
     * 文字列一致へ依存せず、対象DOM要素自身の属性を検証するため、
     * HTMLを安全に解析してXPathを生成する。
     */
    private function createXPath(string|false $html): DOMXPath
    {
        $this->assertIsString($html);

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

    /**
     * 重複IDや対象要素の欠落を見逃さず、ARIA属性などを対象要素自身で検証するため、
     * 指定IDの要素が1件だけ存在することを確認してDOMElementとして返す。
     */
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
