<?php

namespace Tests\Feature;

use App\Models\User;
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
