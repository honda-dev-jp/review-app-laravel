<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * メール未認証ユーザーが認証手続きを開始できるようにするため、
     * 認証案内画面が正常に表示され、初期状態では不要なステータス表示がないことを保証する。
     */
    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);

        $statusElements = $this->createXPath($response->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(0, $statusElements);
    }

    /**
     * メール未認証ユーザーが認証メールを再取得できるようにするため、
     * 実際にVerifyEmail通知が送信され、認証案内画面へ戻って送信完了を支援技術へ伝えるステータスが表示されることを保証する。
     */
    public function test_email_verification_notification_can_be_resent_with_accessible_status(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/verify-email')
            ->post(route('verification.send'));

        $response->assertRedirect('/verify-email');

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
        );

        $pageResponse = $this->actingAs($user)->get('/verify-email');
        $pageResponse->assertOk();

        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame(
            __('A new verification link has been sent to the email address you provided during registration.'),
            trim($statusElement->textContent)
        );
    }

    /**
     * Laravel標準の署名付き認証URLによってメール認証が成立することを確認するため、
     * 認証状態が更新され、Verifiedイベントが発火して認証完了後の画面へ遷移することを保証する。
     */
    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $freshUser = $user->fresh();

        $this->assertInstanceOf(User::class, $freshUser);
        $this->assertTrue($freshUser->hasVerifiedEmail());
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }

    /**
     * 他のメールアドレスから生成したhashによる不正な認証を防ぐため、
     * 有効な署名付きURLであってもhashが一致しない場合は未認証状態が維持されることを保証する。
     */
    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $freshUser = $user->fresh();

        $this->assertInstanceOf(User::class, $freshUser);
        $this->assertFalse($freshUser->hasVerifiedEmail());
    }

    /**
     * 改ざんされた認証URLによるメール認証を防ぐため、
     * 有効な署名がないURLは拒否され、未認証状態が維持されることを保証する。
     */
    public function test_email_is_not_verified_without_valid_signature(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = route('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertForbidden();

        $freshUser = $user->fresh();

        $this->assertInstanceOf(User::class, $freshUser);
        $this->assertFalse($freshUser->hasVerifiedEmail());
    }

    /**
     * 期限切れの認証URLによるメール認証を防ぐため、
     * 有効期限を過ぎた署名付きURLは拒否され、未認証状態が維持されることを保証する。
     */
    public function test_email_is_not_verified_with_expired_signature(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertForbidden();

        $freshUser = $user->fresh();

        $this->assertInstanceOf(User::class, $freshUser);
        $this->assertFalse($freshUser->hasVerifiedEmail());
    }

    /**
     * 他ユーザー用の認証URLによるなりすまし認証を防ぐため、
     * 別ユーザーが有効な署名付きURLへアクセスしても拒否され、
     * URL本来の所有者が未認証状態のままであることを保証する。
     */
    public function test_user_cannot_verify_email_with_another_users_signed_url(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $otherUser->id,
                'hash' => sha1($otherUser->email),
            ]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertForbidden();

        $freshOtherUser = $otherUser->fresh();

        $this->assertInstanceOf(User::class, $freshOtherUser);
        $this->assertFalse($freshOtherUser->hasVerifiedEmail());
    }

    /**
     * 認証済みユーザーに不要な認証案内画面を表示しないため、
     * メール認証画面へアクセスした場合はHOMEへリダイレクトされることを保証する。
     */
    public function test_verified_user_is_redirected_from_email_verification_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    /**
     * 認証済みユーザーへ不要な認証メールを再送しないため、
     * 再送要求時はHOMEへリダイレクトされ、VerifyEmail通知が送信されないことを保証する。
     */
    public function test_verified_user_does_not_receive_verification_notification_again(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('verification.send'));

        $response->assertRedirect(RouteServiceProvider::HOME);

        Notification::assertNotSentTo(
            $user,
            VerifyEmail::class,
        );
    }

    /**
     * 認証済みユーザーによる認証処理の重複実行を防ぐため、
     * 認証リンクを再訪してもVerifiedイベントが再発火しないことを保証する。
     */
    public function test_verified_user_can_revisit_verification_link_without_dispatching_verified_event(): void
    {
        $user = User::factory()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');

        Event::assertNotDispatched(Verified::class);
    }

    /**
     * 文字列一致へ依存せず、対象DOM要素自身の属性を検証するためXPathを生成する。
     */
    private function createXPath(string|false $html): DOMXPath
    {
        $this->assertIsString($html);

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
}
