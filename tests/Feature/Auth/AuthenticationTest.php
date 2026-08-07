<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);

        $xpath = $this->createXPath($response->getContent());

        foreach (['email', 'password'] as $id) {
            $input = $this->getSingleElementById($xpath, $id);
            $labels = $xpath->query(sprintf('//label[@for="%s"]', $id));

            $this->assertFalse($input->hasAttribute('aria-invalid'));
            $this->assertFalse($input->hasAttribute('aria-describedby'));
            $this->assertNotFalse($labels);
            $this->assertCount(1, $labels);
        }

        foreach (['login-email-error', 'login-password-error'] as $id) {
            $elements = $xpath->query(sprintf('//*[@id="%s"]', $id));

            $this->assertNotFalse($elements);
            $this->assertCount(0, $elements);
        }

        $statusElements = $xpath->query('//*[@role="status"]');
        $this->assertNotFalse($statusElements);
        $this->assertCount(0, $statusElements);
    }

    public function test_login_validation_errors_have_accessible_aria_attributes(): void
    {
        $user = User::factory()->create();
        $cases = [
            'email' => [
                'payload' => ['email' => '', 'password' => 'password'],
                'error_id' => 'login-email-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.email'),
                ]),
                'other_field' => 'password',
            ],
            'password' => [
                'payload' => ['email' => $user->email, 'password' => ''],
                'error_id' => 'login-password-error',
                'message' => __('validation.required', [
                    'attribute' => __('validation.attributes.password'),
                ]),
                'other_field' => 'email',
            ],
        ];

        foreach ($cases as $field => $case) {
            $response = $this
                ->from('/login')
                ->followingRedirects()
                ->post('/login', $case['payload']);

            $response->assertOk();

            $xpath = $this->createXPath($response->getContent());
            $input = $this->getSingleElementById($xpath, $field);
            $error = $this->getSingleElementById($xpath, $case['error_id']);
            $otherInput = $this->getSingleElementById($xpath, $case['other_field']);

            $this->assertSame('true', $input->getAttribute('aria-invalid'));
            $this->assertSame($case['error_id'], $input->getAttribute('aria-describedby'));
            $this->assertSame($case['message'], trim($error->textContent));
            $this->assertFalse($otherInput->hasAttribute('aria-invalid'));
            $this->assertFalse($otherInput->hasAttribute('aria-describedby'));
        }
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');

        $pageResponse = $this->get('/');
        $pageResponse->assertOk();

        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame('ログアウトしました。', trim($statusElement->textContent));
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
