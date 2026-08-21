<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会員登録画面の初期表示で入力欄とラベルが正しく関連付けられ、
     * エラーがない状態では不要なARIA属性やエラー要素が表示されないことを保証する。
     */
    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);

        $xpath = $this->createXPath($response->getContent());

        foreach (['name', 'email', 'password'] as $id) {
            $input = $this->getSingleElementById($xpath, $id);
            $labels = $xpath->query(sprintf('//label[@for="%s"]', $id));

            $this->assertFalse($input->hasAttribute('aria-invalid'));
            $this->assertFalse($input->hasAttribute('aria-describedby'));
            $this->assertNotFalse($labels);
            $this->assertCount(1, $labels);
        }

        foreach (['register-name-error', 'register-email-error', 'register-password-error'] as $id) {
            $elements = $xpath->query(sprintf('//*[@id="%s"]', $id));

            $this->assertNotFalse($elements);
            $this->assertCount(0, $elements);
        }
    }

    /**
     * 会員登録のバリデーションエラーを支援技術へ正しく伝えるため、
     * 対象入力欄に必要なARIA属性が付与され、他の入力欄へ誤って影響しないことを保証する。
     */
    public function test_registration_validation_errors_have_accessible_aria_attributes(): void
    {
        $cases = [
            'name' => [
                'payload' => [
                    'name' => '',
                    'email' => 'test@example.com',
                    'password' => 'password',
                    'password_confirmation' => 'password',
                ],
                'error_id' => 'register-name-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.name'),
                ]),
            ],
            'email' => [
                'payload' => [
                    'name' => 'Test User',
                    'email' => '',
                    'password' => 'password',
                    'password_confirmation' => 'password',
                ],
                'error_id' => 'register-email-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.email'),
                ]),
            ],
            'password' => [
                'payload' => [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'password' => '',
                    'password_confirmation' => '',
                ],
                'error_id' => 'register-password-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.password'),
                ]),
            ],
        ];

        foreach ($cases as $field => $case) {
            $response = $this
                ->from('/register')
                ->followingRedirects()
                ->post('/register', $case['payload']);

            $response->assertOk();

            $xpath = $this->createXPath($response->getContent());
            $input = $this->getSingleElementById($xpath, $field);
            $error = $this->getSingleElementById($xpath, $case['error_id']);

            $this->assertSame('true', $input->getAttribute('aria-invalid'));
            $this->assertSame($case['error_id'], $input->getAttribute('aria-describedby'));
            $this->assertSame($case['message'], trim($error->textContent));

            foreach (array_diff(array_keys($cases), [$field]) as $otherField) {
                $otherInput = $this->getSingleElementById($xpath, $otherField);

                $this->assertFalse($otherInput->hasAttribute('aria-invalid'));
                $this->assertFalse($otherInput->hasAttribute('aria-describedby'));
            }
        }
    }

    /**
     * 正常な入力で会員登録でき、登録後にログイン状態となって
     * アプリケーションのホームへ遷移できることを保証する。
     */
    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    /**
     * 会員登録時にLaravel標準のメール認証通知が送信されることを保証する。
     */
    public function test_new_users_receive_email_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
        );
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
