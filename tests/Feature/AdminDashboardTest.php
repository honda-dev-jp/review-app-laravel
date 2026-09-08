<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 未ログインユーザーには認可判定より先に認証を要求し、ログイン画面へ誘導することを保証する。
     */
    public function test_guest_is_redirected_to_login_from_admin_dashboard(): void
    {
        $this
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    /**
     * 一般ユーザーはLaravel標準の認可機構により管理画面へのアクセスを拒否されることを保証する。
     */
    public function test_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        $this
            ->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    /**
     * 管理者が管理画面ダッシュボードを表示でき、ページ名付きのtitleと管理者名が表示されることを保証する。
     */
    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<title>ダッシュボード | 映画レビューアプリ 管理</title>', false)
            ->assertSeeText('管理者：管理テストユーザー');
    }

    /**
     * PCとモバイルの管理画面ナビゲーションにCSRF付きPOST logout formが存在することを保証する。
     */
    public function test_admin_navigation_has_logout_forms_for_desktop_and_mobile(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'));

        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);

        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }

        $xpath = new DOMXPath($document);

        foreach (['管理画面ナビゲーション', 'モバイル管理画面ナビゲーション'] as $navigationLabel) {
            $navigations = $xpath->query(sprintf('//nav[@aria-label="%s"]', $navigationLabel));
            $this->assertNotFalse($navigations);
            $this->assertCount(1, $navigations);

            $navigation = $navigations->item(0);
            $this->assertInstanceOf(DOMElement::class, $navigation);

            $forms = $xpath->query('.//form[@method="POST"]', $navigation);
            $this->assertNotFalse($forms);
            $this->assertCount(1, $forms);

            $form = $forms->item(0);
            $this->assertInstanceOf(DOMElement::class, $form);
            $this->assertSame(route('logout'), $form->getAttribute('action'));

            $csrfInputs = $xpath->query('.//input[@type="hidden" and @name="_token"]', $form);
            $this->assertNotFalse($csrfInputs);
            $this->assertCount(1, $csrfInputs);

            $csrfInput = $csrfInputs->item(0);
            $this->assertInstanceOf(DOMElement::class, $csrfInput);
            $this->assertNotSame('', trim($csrfInput->getAttribute('value')));

            $submitButtons = $xpath->query('.//button[@type="submit"]', $form);
            $this->assertNotFalse($submitButtons);
            $this->assertCount(1, $submitButtons);

            $submitButton = $submitButtons->item(0);
            $this->assertInstanceOf(DOMElement::class, $submitButton);
            $this->assertSame('ログアウト', trim($submitButton->textContent));
        }
    }

    /**
     * admin layoutでtitle slotを指定しない場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_is_not_specified(): void
    {
        $this
            ->blade('<x-admin-layout>Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }

    /**
     * admin layoutで空のtitle slotを指定した場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_is_empty(): void
    {
        $this
            ->blade('<x-admin-layout><x-slot name="title"></x-slot> Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }

    /**
     * admin layoutで空白のみのtitle slotを指定した場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_contains_only_whitespace(): void
    {
        $this
            ->blade('<x-admin-layout><x-slot name="title">   </x-slot> Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }
}
