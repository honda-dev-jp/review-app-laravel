<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile#update-password');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));

        $pageResponse = $this->actingAs($user)->get('/profile');
        $pageResponse->assertOk();

        // 複数フォームの完了通知が混線せず、パスワード変更分だけ出力されることを保証する。
        $xpath = $this->createXPath($pageResponse->getContent());
        $statusElements = $xpath->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));

        // アンカーだけが無関係な位置へ移動しても通らないよう、成功通知を内包する関係を保証する。
        $updatePasswordElements = $xpath->query('//*[@id="update-password"]');
        $this->assertNotFalse($updatePasswordElements);
        $this->assertCount(1, $updatePasswordElements);

        $updatePassword = $updatePasswordElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $updatePassword);

        $anchoredStatusElements = $xpath->query('.//*[@role="status"]', $updatePassword);
        $this->assertNotFalse($anchoredStatusElements);
        $this->assertCount(1, $anchoredStatusElements);

        $anchoredStatusElement = $anchoredStatusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $anchoredStatusElement);
        $this->assertSame($statusElement->getNodePath(), $anchoredStatusElement->getNodePath());

        // 装飾用のチェック記号が成功文言として重複読み上げされないことを保証する。
        $checkMarkElements = $xpath->query('./span[@aria-hidden="true"]', $statusElement);
        $messageElements = $xpath->query('./span[not(@aria-hidden)]', $statusElement);
        $this->assertNotFalse($checkMarkElements);
        $this->assertCount(1, $checkMarkElements);
        $this->assertNotFalse($messageElements);
        $this->assertCount(1, $messageElements);

        $checkMarkElement = $checkMarkElements->item(0);
        $messageElement = $messageElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $checkMarkElement);
        $this->assertInstanceOf(DOMElement::class, $messageElement);
        $this->assertSame('true', $checkMarkElement->getAttribute('aria-hidden'));
        $this->assertSame('✓', trim($checkMarkElement->textContent));
        $this->assertSame(__('Saved.'), trim($messageElement->textContent));

        // 完了メッセージを時間で消す実装へ戻さないため、Alpine.jsの自動非表示属性がないことを確認する。
        foreach (['x-data', 'x-show', 'x-transition', 'x-init'] as $attribute) {
            $this->assertFalse($statusElement->hasAttribute($attribute));
        }
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

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
}
