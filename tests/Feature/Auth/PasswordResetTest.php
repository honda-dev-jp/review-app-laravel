<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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

        $xpath = $this->createXPath($response->getContent());
        $emailInput = $this->getSingleElementById($xpath, 'email');
        $emailLabels = $xpath->query('//label[@for="email"]');

        $this->assertSame('email', $emailInput->getAttribute('id'));
        $this->assertSame('email', $emailInput->getAttribute('name'));
        $this->assertSame('email', $emailInput->getAttribute('type'));
        $this->assertSame('username', $emailInput->getAttribute('autocomplete'));
        $this->assertFalse($emailInput->hasAttribute('aria-invalid'));
        $this->assertFalse($emailInput->hasAttribute('aria-describedby'));
        $this->assertNotFalse($emailLabels);
        $this->assertCount(1, $emailLabels);

        $errorElements = $xpath->query('//*[@id="forgot-email-error"]');
        $this->assertNotFalse($errorElements);
        $this->assertCount(0, $errorElements);

        $statusElements = $xpath->query('//*[@role="status"]');
        $this->assertNotFalse($statusElements);
        $this->assertCount(0, $statusElements);
    }

    /**
     * リセット申請エラーを支援技術へ伝えられるよう、email自身とメッセージの関連付けを保証する。
     */
    public function test_reset_password_link_validation_error_has_accessible_aria_attributes(): void
    {
        $response = $this
            ->from('/forgot-password')
            ->followingRedirects()
            ->post('/forgot-password', ['email' => '']);

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $emailInput = $this->getSingleElementById($xpath, 'email');
        $error = $this->getSingleElementById($xpath, 'forgot-email-error');

        $this->assertSame('true', $emailInput->getAttribute('aria-invalid'));
        $this->assertSame('forgot-email-error', $emailInput->getAttribute('aria-describedby'));
        $this->assertSame(
            __('validation.required', [
                'attribute' => __('validation.attributes.email'),
            ]),
            trim($error->textContent)
        );
    }

    /**
     * 登録済みユーザーがパスワードリセット通知を要求できることを確認する。
     */
    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);

        $response->assertRedirect('/forgot-password');

        $pageResponse = $this->get('/forgot-password');
        $pageResponse->assertOk();

        // 共通コンポーネント側と呼び出し側の双方へroleを付ける回帰を、通知要素1件のDOM検証で防ぐ。
        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame(__(Password::RESET_LINK_SENT), trim($statusElement->textContent));
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
     * 正常表示を誤ってエラー状態として通知しないよう、不要なARIA属性とエラー要素の非出力を保証する。
     */
    public function test_reset_password_screen_does_not_output_error_aria_attributes_normally(): void
    {
        $response = $this->get('/reset-password/test-token?email=test%40example.com');

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());

        foreach (['email', 'password'] as $id) {
            $input = $this->getSingleElementById($xpath, $id);
            $labels = $xpath->query(sprintf('//label[@for="%s"]', $id));

            $this->assertFalse($input->hasAttribute('aria-invalid'));
            $this->assertFalse($input->hasAttribute('aria-describedby'));
            $this->assertNotFalse($labels);
            $this->assertCount(1, $labels);
        }

        foreach (['reset-email-error', 'reset-password-error'] as $id) {
            $elements = $xpath->query(sprintf('//*[@id="%s"]', $id));

            $this->assertNotFalse($elements);
            $this->assertCount(0, $elements);
        }
    }

    /**
     * 再設定エラーを各入力へ正しく関連付け、対象外の確認入力へARIA属性を付けないことを保証する。
     */
    public function test_reset_password_validation_errors_have_accessible_aria_attributes(): void
    {
        $response = $this
            ->from('/reset-password/test-token')
            ->followingRedirects()
            ->post('/reset-password', [
                'token' => 'test-token',
                'email' => '',
                'password' => '',
                'password_confirmation' => '',
            ]);

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $cases = [
            'email' => [
                'error_id' => 'reset-email-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.email'),
                ]),
            ],
            'password' => [
                'error_id' => 'reset-password-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.password'),
                ]),
            ],
        ];

        foreach ($cases as $field => $case) {
            $input = $this->getSingleElementById($xpath, $field);
            $error = $this->getSingleElementById($xpath, $case['error_id']);

            $this->assertSame('true', $input->getAttribute('aria-invalid'));
            $this->assertSame($case['error_id'], $input->getAttribute('aria-describedby'));
            $this->assertSame($case['message'], trim($error->textContent));
        }

        $confirmation = $this->getSingleElementById($xpath, 'password_confirmation');
        $this->assertFalse($confirmation->hasAttribute('aria-invalid'));
        $this->assertFalse($confirmation->hasAttribute('aria-describedby'));
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

            $loginResponse = $this->get(route('login'));
            $loginResponse->assertOk();

            $statusElements = $this->createXPath($loginResponse->getContent())
                ->query('//*[@role="status"]');

            $this->assertNotFalse($statusElements);
            $this->assertCount(1, $statusElements);

            $statusElement = $statusElements->item(0);
            $this->assertInstanceOf(DOMElement::class, $statusElement);
            $this->assertSame('status', $statusElement->getAttribute('role'));
            $this->assertSame(__(Password::PASSWORD_RESET), trim($statusElement->textContent));

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

    /**
     * 文字列一致へ依存せず、対象DOM要素自身の属性を検証するためXPathを生成する。
     */
    private function createXPath(string $html): DOMXPath
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));

            return new DOMXPath($document);
        } finally {
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
