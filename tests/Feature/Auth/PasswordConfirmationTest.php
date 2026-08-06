<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);

        $xpath = $this->createXPath($response->getContent());
        $passwordInput = $this->getSingleElementById($xpath, 'password');
        $passwordLabels = $xpath->query('//label[@for="password"]');

        $this->assertFalse($passwordInput->hasAttribute('aria-invalid'));
        $this->assertFalse($passwordInput->hasAttribute('aria-describedby'));
        $this->assertNotFalse($passwordLabels);
        $this->assertCount(1, $passwordLabels);

        $errorElements = $xpath->query('//*[@id="confirm-password-error"]');
        $this->assertNotFalse($errorElements);
        $this->assertCount(0, $errorElements);
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }

    public function test_password_confirmation_error_has_accessible_aria_attributes(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/confirm-password')
            ->followingRedirects()
            ->post('/confirm-password', [
                'password' => 'wrong-password',
            ]);

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $passwordInput = $this->getSingleElementById($xpath, 'password');
        $error = $this->getSingleElementById($xpath, 'confirm-password-error');

        $this->assertSame('true', $passwordInput->getAttribute('aria-invalid'));
        $this->assertSame('confirm-password-error', $passwordInput->getAttribute('aria-describedby'));
        $this->assertSame(__('auth.password'), trim($error->textContent));
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
