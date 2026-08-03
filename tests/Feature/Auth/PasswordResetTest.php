<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * パスワードリセット申請画面を表示できることを確認する。
     */
    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    /**
     * 登録済みユーザーがパスワードリセット通知を要求できることを確認する。
     */
    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * 通知に含まれる有効なトークンからパスワード再設定画面を表示できることを確認する。
     */
    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    /**
     * 有効なトークンで新しいパスワードを設定でき、DBへ更新結果が保存されることを確認する。
     */
    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Factory既定値と異なる値を使い、成功レスポンスだけでは見逃すDB更新漏れをHash::check()で検出する。
        $newPassword = 'new-password-1234';

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($newPassword, $user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            $user->refresh();

            $this->assertTrue(Hash::check($newPassword, $user->password));

            return true;
        });
    }

    /**
     * 無効なトークンによる再設定が拒否され、利用者の現在のパスワードが維持されることを確認する。
     */
    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();
        $originalPasswordHash = $user->password;

        $response = $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
        ]);

        // 翻訳変更で壊れないよう、メッセージ本文ではなくエラー対象フィールドを検証する。
        $response->assertSessionHasErrors('email');

        // トークン検証失敗時の意図しない更新を直接検出し、Factory既定の平文パスワードには依存しない。
        $this->assertSame($originalPasswordHash, $user->fresh()->password);
    }

    /**
     * 確認入力が一致しない場合は再設定が拒否され、利用者の現在のパスワードが維持されることを確認する。
     */
    public function test_password_cannot_be_reset_when_password_confirmation_does_not_match(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $originalPasswordHash = $user->password;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($originalPasswordHash, $user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password-1234',
                'password_confirmation' => 'different-password-1234',
            ]);

            $response->assertSessionHasErrors('password');

            // バリデーション失敗時の意図しない更新を直接検出し、Factory既定の平文パスワードには依存しない。
            $this->assertSame($originalPasswordHash, $user->fresh()->password);

            return true;
        });
    }

    /**
     * パスワードルールを満たさない場合は再設定が拒否され、利用者の現在のパスワードが維持されることを確認する。
     */
    public function test_password_cannot_be_reset_when_password_does_not_meet_rules(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $originalPasswordHash = $user->password;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($originalPasswordHash, $user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

            $response->assertSessionHasErrors('password');

            // ルール違反時の意図しない更新を検出するため、バリデーション前後のハッシュ同一性を確認する。
            $this->assertSame($originalPasswordHash, $user->fresh()->password);

            return true;
        });
    }

    /**
     * 未登録メールアドレスでは申請が拒否され、通知処理が実行されないことを確認する。
     */
    public function test_password_reset_link_cannot_be_requested_for_unregistered_email(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', [
            'email' => 'unregistered@example.com',
        ]);

        $response->assertSessionHasErrors('email');

        // 未登録ユーザーへの通知処理が走らないことを、画面上のエラーとは別に保証する。
        Notification::assertNothingSent();
    }
}
