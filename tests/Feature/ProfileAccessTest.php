<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * プロフィール画面が認証済みユーザーだけに公開されることを保証する。
     */
    public function test_guest_cannot_view_profile_page(): void
    {
        $this
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));
    }

    /**
     * Issue #93でアカウント管理はメール認証前でも使える既存会員機能として維持するため、
     * verified ミドルウェアが過剰適用されても検出できるように未認証ユーザーのプロフィール表示を保証する。
     */
    public function test_unverified_user_can_view_profile_page(): void
    {
        $user = User::factory()->unverified()->create();

        $this
            ->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();
    }

    /**
     * 未ログインの退会リクエストではユーザーが削除されないことを保証する。
     */
    public function test_guest_cannot_delete_account(): void
    {
        $user = User::factory()->create();

        $this
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }

    /**
     * 未ログインのプロフィール更新リクエストではプロフィールが更新されないことを保証する。
     */
    public function test_guest_cannot_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => '変更前ユーザー',
            'profile' => '変更前プロフィール',
        ]);

        $this
            ->patch(route('profile.update'), [
                'name' => '変更後ユーザー',
                'email' => 'changed@example.com',
                'profile' => '変更後プロフィール',
            ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '変更前ユーザー',
            'email' => $user->email,
            'profile' => '変更前プロフィール',
        ]);
    }
}
