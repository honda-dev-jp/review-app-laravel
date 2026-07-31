<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * アカウント画面を統一された表示名称で表示できることを確認する。
     */
    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $xpath = $this->createXPath($response->getContent());
        $accountHeadings = $xpath->query('//h2[normalize-space(.)="アカウント"]');
        $accountNavigationLinks = $xpath->query(sprintf(
            '//a[@href="%s" and normalize-space(.)="アカウント"]',
            route('profile.edit')
        ));

        $this->assertNotFalse($accountHeadings);
        $this->assertCount(1, $accountHeadings);
        $this->assertNotFalse($accountNavigationLinks);
        $this->assertCount(3, $accountNavigationLinks);

        $response
            ->assertOk()
            ->assertDontSeeText('プロフィール編集');
    }

    /**
     * プロフィール情報を更新できることを確認する。
     */
    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * アカウント画面から自己紹介を更新できることを確認する。
     */
    public function test_profile_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => '映画の感想を書くのが好きです。',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('映画の感想を書くのが好きです。', $user->profile);
    }

    /**
     * 自己紹介を1000文字まで保存できることを確認する。
     */
    public function test_profile_can_be_updated_with_1000_characters(): void
    {
        $user = User::factory()->create();
        $profile = str_repeat('a', 1000);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => $profile,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame($profile, $user->refresh()->profile);
    }

    /**
     * 自己紹介が1001文字の場合にバリデーションエラーになることを確認する。
     */
    public function test_profile_cannot_be_updated_with_more_than_1000_characters(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('profile');
    }

    /**
     * プロフィール更新リクエストに権限や画像パスを含めても更新されないことを確認する。
     */
    public function test_role_and_avatar_path_cannot_be_updated_from_profile_information(): void
    {
        $user = User::factory()->create();
        $user->avatar_path = 'avatars/current.png';
        $user->save();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => 'プロフィール本文',
                'role' => 'admin',
                'avatar_path' => 'avatars/changed.png',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('user', $user->role);
        $this->assertSame('avatars/current.png', $user->avatar_path);
    }

    /**
     * アカウント画面に退会フォームが表示されることを確認する。
     */
    public function test_delete_account_form_is_displayed_on_profile_page(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $xpath = $this->createXPath($response->getContent());
        $forms = $xpath->query(sprintf(
            '//form[@action="%s" and .//input[@type="hidden" and @name="_method" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="DELETE"]]',
            route('profile.destroy')
        ));

        $this->assertNotFalse($forms);
        $this->assertCount(1, $forms);

        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));

        $methodInputs = $xpath->query(
            './/input[@type="hidden" and @name="_method" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="DELETE"]',
            $form
        );
        $csrfInputs = $xpath->query(
            './/input[@type="hidden" and @name="_token" and string-length(@value) > 0]',
            $form
        );
        $passwordInputs = $xpath->query(
            './/input[@type="password" and @name="password"]',
            $form
        );

        $this->assertNotFalse($methodInputs);
        $this->assertCount(1, $methodInputs);
        $this->assertNotFalse($csrfInputs);
        $this->assertCount(1, $csrfInputs);
        $this->assertNotFalse($passwordInputs);
        $this->assertCount(1, $passwordInputs);

        $response
            ->assertOk()
            ->assertSeeText(__('Delete Account'))
            ->assertSeeText(__(
                'Once your account is deleted, it cannot be restored. Your reviews and replies will remain and be displayed anonymously. Please enter your current password to confirm account deletion.'
            ));
    }

    /**
     * メールアドレスを変更しない場合は、メール認証済み状態が維持されることを確認する。
     */
    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * 正しいパスワードでユーザーを物理削除し、セッションを無効化してCSRFトークンを再生成し、
     * ログアウト後のhome画面に完了メッセージが表示されることを確認する。
     */
    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        $this->withSession([
            'account-deletion-marker' => 'value-to-be-invalidated',
        ]);
        $csrfTokenBeforeDeletion = csrf_token();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'アカウントを削除しました。')
            ->assertSessionMissing('account-deletion-marker')
            ->assertRedirect(route('home'));

        $this->assertNotSame($csrfTokenBeforeDeletion, csrf_token());
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('アカウントを削除しました。');
    }

    /**
     * 退会後も投稿済みのレビューと返信コメントが残り、
     * 投稿者のuser_idだけがnullになることを確認する。
     */
    public function test_reviews_and_comments_remain_and_their_user_ids_become_null_after_account_deletion(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'body' => '退会後も残るレビューです。',
        ]);
        $comment = ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => '退会後も残る返信コメントです。',
        ]);

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => null,
            'item_id' => $review->item_id,
            'body' => '退会後も残るレビューです。',
        ]);
        $this->assertDatabaseHas('review_comments', [
            'id' => $comment->id,
            'review_id' => $review->id,
            'user_id' => null,
            'body' => '退会後も残る返信コメントです。',
        ]);
    }

    /**
     * 投稿者のuser_idがnullのレビューと返信コメントが、
     * 作品詳細画面でそれぞれ「匿名」と表示されることを確認する。
     */
    public function test_reviews_and_comments_without_user_are_displayed_as_anonymous(): void
    {
        $review = Review::factory()->create([
            'user_id' => null,
            'body' => '匿名ユーザーのレビューです。',
        ]);
        ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => null,
            'parent_id' => null,
            'body' => '匿名ユーザーの返信コメントです。',
        ]);

        $response = $this->get(route('items.show', $review->item_id));

        $response
            ->assertOk()
            ->assertSeeTextInOrder([
                '匿名',
                '匿名ユーザーのレビューです。',
                '匿名',
                '匿名ユーザーの返信コメントです。',
            ]);
    }

    /**
     * パスワードが誤っている場合は退会できず、
     * ログイン状態とユーザー・関連データが変更されないことを確認する。
     */
    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'body' => '削除されないレビューです。',
        ]);
        $comment = ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => '削除されない返信コメントです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $user->id,
            'item_id' => $review->item_id,
            'body' => '削除されないレビューです。',
        ]);
        $this->assertDatabaseHas('review_comments', [
            'id' => $comment->id,
            'review_id' => $review->id,
            'user_id' => $user->id,
            'body' => '削除されない返信コメントです。',
        ]);
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
}
