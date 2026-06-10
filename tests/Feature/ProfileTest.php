<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * プロフィール編集画面から自己紹介を更新できることを確認する。
     */
    public function test_profile_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => '映画の感想を書くのが好きです。',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('映画の感想を書くのが好きです。', $user->profile);
    }

    /**
     * 自己紹介を1000文字まで保存できることを確認する。
     */
    public function test_profile_can_be_updated_with_1000_characters(): void
    {
        $user = User::factory()->create();
        $profile = str_repeat('a', 1000);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => $profile,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame($profile, $user->refresh()->profile);
    }

    /**
     * 自己紹介が1001文字の場合にバリデーションエラーになることを確認する。
     */
    public function test_profile_cannot_be_updated_with_more_than_1000_characters(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('profile');
    }

    /**
     * プロフィール更新リクエストに権限や画像パスを含めても更新されないことを確認する。
     */
    public function test_role_and_avatar_path_cannot_be_updated_from_profile_information(): void
    {
        $user = User::factory()->create();
        $user->avatar_path = 'avatars/current.png';
        $user->save();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => 'プロフィール本文',
                'role' => 'admin',
                'avatar_path' => 'avatars/changed.png',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('user', $user->role);
        $this->assertSame('avatars/current.png', $user->avatar_path);
    }

    /**
     * プロフィール編集画面に退会フォーム導線が表示されないことを確認する。
     */
    public function test_delete_account_form_is_not_displayed_on_profile_page(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response
            ->assertOk()
            ->assertDontSeeText(__('Delete Account'))
            ->assertDontSeeText(__('Permanently delete your account.'))
            ->assertDontSeeText(__('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
